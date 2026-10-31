<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\DTOs\AttachmentRef;
use App\Domain\Ingestion\DTOs\EnvelopeAttachment;
use App\Domain\Ingestion\DTOs\SubmissionEnvelope;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\Jobs\FetchAttachmentsJob;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Ingestion\SubmissionParser;
use App\Domain\Validation\Actions\MarkSuperseded;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * One page of raw rows from one source, committed in ONE transaction (ARCHITECTURE.md §5.3, §5.6):
 * the one path the pull, the backfill and the reconcile sweep of every source take. The source is
 * the sync row's; its normaliser reads each row.
 *
 * Before the transaction: the attachments each new row expects (no HTTP while the transaction
 * holds locks): a row that lists its own (Kobo's _attachments) gives them; one that only counts
 * them (ODK's __system.attachmentsExpected) asks the source for the list. Inside it, in order: the
 * raw rows inserted verbatim ON CONFLICT (instance_id) DO NOTHING (rows not returned are
 * duplicates; one whose instance another source already holds is a cross-source duplicate, D-60,
 * counted apart and logged with both sources, never stored twice); the new ones parsed from
 * the stored payload; edits of quarantined submissions superseded (MarkSuperseded); the batch run
 * through ProcessParsedBatch with one ValidationContext; their attachments recorded pending; the
 * run's counters; and last the form's sync row. A crash anywhere rolls all of it back, so the
 * caller replays the page. After commit, a pull queues FetchAttachmentsJob for each submission
 * with files; a backfill does not ($fetchAttachments false).
 *
 * The sync row is updated atomically, because a pull and a backfill may both write it:
 * submission_count is incremented in SQL, a timestamp cursor only ever moves forward (GREATEST),
 * and backfill_checkpoint is set as given.
 */
final class ProcessSubmissionsPage
{
    /** The sync columns a page may move. */
    private const CURSORS = ['cursor_at', 'edit_cursor_at'];

    public function __construct(
        private readonly SubmissionParser $parser,
        private readonly SourceRegistry $sources,
        private readonly ProcessParsedBatch $batch,
        private readonly MarkSuperseded $superseded,
        private readonly RecordAttachments $attachments,
    ) {}

    /**
     * @param  list<array<array-key, mixed>>  $rows  raw entries, each at most once in a run
     * @param  array<string, CarbonImmutable|int|null>  $changes  cursor_at / edit_cursor_at (moved forward
     *                                                            only), backfill_checkpoint (set); null leaves it
     */
    public function execute(PullRun $run, SourceSync $sync, array $rows, array $changes = [], bool $fetchAttachments = true): void
    {
        $key = (string) $sync->source()->value('key');
        $normaliser = $this->sources->normaliser($key);
        $envelopes = array_map($normaliser->normalise(...), $rows);
        $expected = $this->expectedAttachments($key, $rows, $envelopes);

        $withFiles = DB::transaction(function () use ($run, $sync, $rows, $changes, $expected, $key, $normaliser, $envelopes): array {
            $inserted = $this->insert($envelopes, $rows, $sync->source_id, $sync->formId());
            $crossSource = self::crossSource($envelopes, $inserted, $sync->source_id, $key);
            $edits = self::editsByExternalId(array_values($inserted));
            $submissions = $inserted === [] ? [] : Submission::query()->whereIn('id', array_values($inserted))->get()->keyBy('instance_id')->all();

            $batch = [];
            foreach ($envelopes as $envelope) {
                $submission = $submissions[$envelope->meta->instanceId] ?? null;
                if ($submission instanceof Submission) {
                    // The stored payload, read again: what reprocessing will read (hard rule 8).
                    $parsed = $this->parser->parse($normaliser->normalise($submission->payload));
                    $previous = $edits[$submission->id] ?? null;
                    $batch[] = [$submission, $previous === null ? $parsed : $parsed->asEditOf($previous)];
                }
            }

            // Fix at source: an edit of a quarantined submission resolves its open record.
            foreach ($batch as [$submission]) {
                if ($submission->deprecated_id !== null) {
                    $this->superseded->execute($submission);
                }
            }

            $counts = [SubmissionStatus::Accepted->value => 0, SubmissionStatus::Flagged->value => 0, SubmissionStatus::Quarantined->value => 0, SubmissionStatus::OutOfScope->value => 0];
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
                'parsed' => $run->parsed + count($batch),
                'duplicates' => $run->duplicates + count($rows) - count($inserted) - $crossSource,
                'cross_source_duplicates' => $run->cross_source_duplicates + $crossSource,
                'accepted' => $run->accepted + $counts[SubmissionStatus::Accepted->value],
                'flagged' => $run->flagged + $counts[SubmissionStatus::Flagged->value],
                'quarantined' => $run->quarantined + $counts[SubmissionStatus::Quarantined->value],
                'out_of_scope' => $run->out_of_scope + $counts[SubmissionStatus::OutOfScope->value],
            ]);

            $this->moveSync($sync, $changes, $envelopes, count($inserted));

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
     * The last row's instance is noted beside a moved cursor (the reconcile sweep moves none).
     *
     * @param  array<string, CarbonImmutable|int|null>  $changes
     * @param  list<SubmissionEnvelope>  $envelopes
     */
    private function moveSync(SourceSync $sync, array $changes, array $envelopes, int $inserted): void
    {
        $pdo = DB::connection()->getPdo();
        $update = ['submission_count' => DB::raw('submission_count + '.$inserted), 'updated_at' => now()];
        $cursorMoved = array_filter(array_intersect_key($changes, array_flip(self::CURSORS)), fn ($v): bool => $v !== null) !== [];
        if ($envelopes !== [] && $cursorMoved) {
            $update['cursor_instance_id'] = $envelopes[array_key_last($envelopes)]->meta->instanceId;
        }
        foreach ($changes as $column => $value) {
            if ($value === null) {
                continue;
            }
            if (in_array($column, self::CURSORS, true) && $value instanceof CarbonImmutable) {
                // In the app's zone with microseconds, as the connection reads it (D-30).
                $time = $pdo->quote($value->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s.uP'));
                $update[$column] = DB::raw("GREATEST(COALESCE({$column}, {$time}::timestamptz), {$time}::timestamptz)");
            } elseif ($column === 'backfill_checkpoint' && is_int($value)) {
                $update[$column] = $value;
            } else {
                throw new InvalidArgumentException("The page cannot move the sync column {$column}.");
            }
        }

        DB::table('source_syncs')->where('id', $sync->id)->update($update);
    }

    /**
     * The files each row not stored yet expects: those its envelope lists (Kobo's _attachments),
     * or, for a row that only counts them (ODK's __system.attachmentsExpected), the source's list
     * under the submission's logical ID (Central's __id).
     *
     * @param  list<array<array-key, mixed>>  $rows
     * @param  list<SubmissionEnvelope>  $envelopes  one per row
     * @return array<string, list<AttachmentRef>> instance ID => the files
     */
    private function expectedAttachments(string $key, array $rows, array $envelopes): array
    {
        $listed = [];
        $ask = [];
        foreach ($envelopes as $i => $envelope) {
            $meta = $envelope->meta;
            if ($meta->attachments !== []) {
                $listed[$meta->instanceId] = array_map(fn (EnvelopeAttachment $a): AttachmentRef => new AttachmentRef($a->name, $a->url !== null), $meta->attachments);
            } else {
                $expected = FieldMap::get($rows[$i], '__system.attachmentsExpected');
                if (is_numeric($expected) && (int) $expected > 0) {
                    $ask[$meta->instanceId] = $meta->externalId ?? $meta->instanceId;
                }
            }
        }
        if ($listed === [] && $ask === []) {
            return [];
        }

        $ids = array_map(strval(...), [...array_keys($listed), ...array_keys($ask)]);
        $stored = DB::table('submissions')->whereIn('instance_id', $ids)->pluck('instance_id')->flip()->all();
        $files = [];
        foreach ($listed as $instanceId => $refs) {
            if (! isset($stored[$instanceId])) {
                $files[(string) $instanceId] = $refs;
            }
        }
        foreach ($ask as $instanceId => $logicalId) {
            if (! isset($stored[$instanceId])) {
                $files[(string) $instanceId] = $this->sources->adapter($key)->attachmentList($logicalId);
            }
        }

        return $files;
    }

    /**
     * The rows not inserted whose instance another source already holds (D-60): counted apart from
     * this source's own repeats, and logged naming both sources (IDs only, never payload values).
     *
     * @param  list<SubmissionEnvelope>  $envelopes
     * @param  array<string, int>  $inserted
     */
    private static function crossSource(array $envelopes, array $inserted, int $sourceId, string $key): int
    {
        $skipped = [];
        foreach ($envelopes as $envelope) {
            if (! isset($inserted[$envelope->meta->instanceId])) {
                $skipped[] = $envelope->meta->instanceId;
            }
        }
        if ($skipped === []) {
            return 0;
        }

        $elsewhere = DB::table('submissions as s')
            ->join('submission_sources as src', 'src.id', '=', 's.source_id')
            ->whereIn('s.instance_id', array_values(array_unique($skipped)))
            ->where('s.source_id', '<>', $sourceId)
            ->pluck('src.key', 's.instance_id')->all();
        foreach ($elsewhere as $instanceId => $storedFrom) {
            Log::warning('Cross-source duplicate submission', ['instance_id' => (string) $instanceId, 'source' => $key, 'stored_from' => (string) $storedFrom]);
        }

        // A row repeated within the page counts once per row, as a duplicate does.
        return count(array_filter($skipped, fn (string $id): bool => isset($elsewhere[$id])));
    }

    /**
     * The raw rows, verbatim, in one statement; rows already stored are skipped by the database.
     * Identity and times come from each row's envelope: external_id is the source's logical ID (the
     * same for every version of a submission), source_updated_at when it was last edited there.
     *
     * @param  list<SubmissionEnvelope>  $envelopes  one per row, in order
     * @param  list<array<array-key, mixed>>  $rows
     * @return array<string, int> instance ID => submission ID, for the rows actually inserted
     */
    private function insert(array $envelopes, array $rows, int $sourceId, string $formId): array
    {
        if ($rows === []) {
            return [];
        }

        $now = now()->toIso8601String();
        $values = [];
        $bindings = [];
        foreach ($rows as $i => $row) {
            $meta = $envelopes[$i]->meta;
            $values[] = '(?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)';
            $submitted = $meta->submittedAt ?? CarbonImmutable::createFromTimestampUTC(0);
            array_push($bindings,
                $sourceId,
                $meta->externalId,
                $meta->instanceId,
                $meta->deprecatedId,
                $formId,
                $meta->formVersion,
                json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $submitted->toIso8601String(),
                $meta->updatedAt?->toIso8601String(),
                $now,
                SubmissionStatus::Received->value,
            );
        }

        $inserted = DB::select(
            'INSERT INTO submissions (source_id, external_id, instance_id, deprecated_id, form_ref, form_version, payload, submitted_at, source_updated_at, received_at, status) VALUES '
            .implode(', ', $values).' ON CONFLICT (instance_id) DO NOTHING RETURNING id, instance_id',
            $bindings,
        );

        $ids = [];
        foreach ($inserted as $row) {
            $ids[(string) $row->instance_id] = (int) $row->id;
        }

        return $ids;
    }

    /**
     * Edits a source sent without deprecatedID (D-61): a new row whose
     * external_id the source already sent, under another instance ID, replaces the latest version
     * not yet replaced. Its deprecated_id is set to that version (the payload is untouched), so
     * the edit takes the path every edit takes (MarkSuperseded, the assessment updated in place).
     *
     * @param  list<int>  $ids  submissions this page inserted
     * @return array<int, string> submission ID => instance ID it replaces
     */
    private static function editsByExternalId(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = DB::select(<<<'SQL'
            SELECT n.id, prev.instance_id
            FROM submissions n
            JOIN LATERAL (
                SELECT p.instance_id FROM submissions p
                WHERE p.source_id = n.source_id AND p.external_id = n.external_id AND p.id < n.id
                    AND p.instance_id <> n.instance_id AND p.superseded_by_id IS NULL
                ORDER BY p.id DESC LIMIT 1
            ) prev ON true
            WHERE n.id = ANY(?::bigint[]) AND n.deprecated_id IS NULL AND n.external_id IS NOT NULL
            ORDER BY n.id
            SQL, ['{'.implode(',', $ids).'}']);

        $edits = [];
        foreach ($found as $row) {
            $edits[(int) $row->id] = (string) $row->instance_id;
            DB::update('UPDATE submissions SET deprecated_id = ? WHERE id = ?', [(string) $row->instance_id, (int) $row->id]);
        }

        return $edits;
    }
}
