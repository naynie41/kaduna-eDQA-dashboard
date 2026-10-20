<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\AttachmentRef;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Jobs\FetchAttachmentsJob;
use App\Domain\Ingestion\Models\OdkFormSync;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Odk\FieldMap;
use App\Domain\Ingestion\Odk\OdkRow;
use App\Domain\Ingestion\Odk\SubmissionParser;
use App\Domain\Validation\Actions\MarkSuperseded;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One page of raw ODK rows, committed in ONE transaction (ARCHITECTURE.md §5.3, §5.6): the one
 * path both the pull and the backfill take.
 *
 * Before the transaction: Central's attachment list for each new row expecting files (no HTTP
 * while the transaction holds locks). Inside it, in order: the raw rows inserted verbatim
 * ON CONFLICT (instance_id) DO NOTHING (rows not returned are duplicates); the new ones parsed from
 * the stored payload; edits of quarantined submissions superseded (MarkSuperseded); the batch run
 * through ProcessParsedBatch with one ValidationContext; their attachments recorded pending; the
 * run's counters; and last the form's sync row. A crash anywhere rolls all of it back, so the
 * caller replays the page. After commit, a pull queues FetchAttachmentsJob for each submission
 * with files; a backfill does not ($fetchAttachments false).
 *
 * The sync row is updated atomically, because a pull and a backfill may both write it:
 * submission_count is incremented in SQL, a timestamp cursor only ever moves forward (GREATEST),
 * and backfill_skip is set as given.
 */
final class ProcessSubmissionsPage
{
    /** The sync columns a page may move. */
    private const CURSORS = ['last_pulled_at', 'last_edit_at'];

    public function __construct(
        private readonly SubmissionSource $source,
        private readonly SubmissionParser $parser,
        private readonly ProcessParsedBatch $batch,
        private readonly MarkSuperseded $superseded,
        private readonly RecordAttachments $attachments,
    ) {}

    /**
     * @param  list<array<array-key, mixed>>  $rows  raw entries, each at most once in a run
     * @param  array<string, CarbonImmutable|int|null>  $changes  last_pulled_at / last_edit_at (moved forward
     *                                                            only), backfill_skip (set); null leaves it
     */
    public function execute(OdkPullRun $run, OdkFormSync $sync, array $rows, array $changes = [], bool $fetchAttachments = true): void
    {
        $expected = $this->expectedAttachments($rows);

        $withFiles = DB::transaction(function () use ($run, $sync, $rows, $changes, $expected): array {
            $inserted = $this->insert($rows, (string) $sync->form_id);
            $submissions = $inserted === [] ? [] : Submission::query()->whereIn('id', array_values($inserted))->get()->keyBy('instance_id')->all();

            $batch = [];
            foreach ($rows as $row) {
                $submission = $submissions[OdkRow::instanceId($row)] ?? null;
                if ($submission instanceof Submission) {
                    $batch[] = [$submission, $this->parser->parse($submission->payload)];
                }
            }

            // Fix at source: an edit of a quarantined submission resolves its open record.
            foreach ($batch as [$submission]) {
                if ($submission->deprecated_id !== null) {
                    $this->superseded->execute($submission);
                }
            }

            $counts = [SubmissionStatus::Accepted->value => 0, SubmissionStatus::Flagged->value => 0, SubmissionStatus::Quarantined->value => 0];
            foreach ($batch === [] ? [] : $this->batch->execute($batch) as $outcome) {
                $counts[$outcome->status->value] = ($counts[$outcome->status->value] ?? 0) + 1;
            }

            // The attachments of the rows this page stored, pending, with their submissions.
            $bySubmission = [];
            foreach ($expected as $instanceId => $refs) {
                if (isset($inserted[$instanceId])) {
                    $bySubmission[$inserted[$instanceId]] = $refs;
                }
            }
            $withFiles = $this->attachments->execute($bySubmission);

            $run->update([
                'fetched' => $run->fetched + count($rows),
                'duplicates' => $run->duplicates + count($rows) - count($inserted),
                'accepted' => $run->accepted + $counts[SubmissionStatus::Accepted->value],
                'flagged' => $run->flagged + $counts[SubmissionStatus::Flagged->value],
                'quarantined' => $run->quarantined + $counts[SubmissionStatus::Quarantined->value],
            ]);

            $this->moveSync($sync, $changes, $rows, count($inserted));

            return $withFiles;
        });

        if ($fetchAttachments) {
            foreach ($withFiles as $submissionId) {
                FetchAttachmentsJob::dispatch($submissionId);
            }
        }
    }

    /**
     * The sync row's last statement in the page.
     *
     * @param  array<string, CarbonImmutable|int|null>  $changes
     * @param  list<array<array-key, mixed>>  $rows
     */
    private function moveSync(OdkFormSync $sync, array $changes, array $rows, int $inserted): void
    {
        $pdo = DB::connection()->getPdo();
        $update = ['submission_count' => DB::raw('submission_count + '.$inserted), 'updated_at' => now()];
        if ($rows !== []) {
            $update['last_instance_id'] = OdkRow::instanceId($rows[array_key_last($rows)]);
        }
        foreach ($changes as $column => $value) {
            if ($value === null) {
                continue;
            }
            if (in_array($column, self::CURSORS, true) && $value instanceof CarbonImmutable) {
                // In the app's zone with microseconds, as the connection reads it (D-30).
                $time = $pdo->quote($value->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s.uP'));
                $update[$column] = DB::raw("GREATEST(COALESCE({$column}, {$time}::timestamptz), {$time}::timestamptz)");
            } elseif ($column === 'backfill_skip' && is_int($value)) {
                $update[$column] = $value;
            } else {
                throw new InvalidArgumentException("The page cannot move the sync column {$column}.");
            }
        }

        DB::table('odk_form_syncs')->where('id', $sync->id)->update($update);
    }

    /**
     * What ODK lists for each row that expects files (__system.attachmentsExpected) and is not
     * stored yet; Central lists them under the submission's root ID (__id).
     *
     * @param  list<array<array-key, mixed>>  $rows
     * @return array<string, list<AttachmentRef>> instance ID => the files
     */
    private function expectedAttachments(array $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            $expected = FieldMap::get($row, '__system.attachmentsExpected');
            if (is_numeric($expected) && (int) $expected > 0) {
                $candidates[OdkRow::instanceId($row)] = OdkRow::rootId($row);
            }
        }
        if ($candidates === []) {
            return [];
        }

        $stored = DB::table('submissions')->whereIn('instance_id', array_keys($candidates))->pluck('instance_id')->flip()->all();
        $listed = [];
        foreach ($candidates as $instanceId => $rootId) {
            if (! isset($stored[$instanceId])) {
                $listed[$instanceId] = $this->source->attachmentList($rootId);
            }
        }

        return $listed;
    }

    /**
     * The raw rows, verbatim, in one statement; rows already stored are skipped by the database.
     *
     * @param  list<array<array-key, mixed>>  $rows
     * @return array<string, int> instance ID => submission ID, for the rows actually inserted
     */
    private function insert(array $rows, string $formId): array
    {
        if ($rows === []) {
            return [];
        }

        $now = now()->toIso8601String();
        $values = [];
        $bindings = [];
        foreach ($rows as $row) {
            $values[] = '(?, ?, ?, ?, ?::jsonb, ?, ?, ?)';
            $submitted = OdkRow::time(FieldMap::get($row, '__system.submissionDate')) ?? CarbonImmutable::createFromTimestampUTC(0);
            array_push($bindings,
                OdkRow::instanceId($row),
                OdkRow::text(FieldMap::get($row, 'meta.deprecatedID')),
                $formId,
                OdkRow::text(FieldMap::get($row, '__system.formVersion')) ?? '',
                json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $submitted->toIso8601String(),
                $now,
                SubmissionStatus::Received->value,
            );
        }

        $inserted = DB::select(
            'INSERT INTO submissions (instance_id, deprecated_id, form_id, form_version, payload, submitted_at, received_at, status) VALUES '
            .implode(', ', $values).' ON CONFLICT (instance_id) DO NOTHING RETURNING id, instance_id',
            $bindings,
        );

        $ids = [];
        foreach ($inserted as $row) {
            $ids[(string) $row->instance_id] = (int) $row->id;
        }

        return $ids;
    }
}
