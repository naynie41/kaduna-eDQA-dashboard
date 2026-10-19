<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\AttachmentRef;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Exceptions\OdkException;
use App\Domain\Ingestion\Exceptions\OdkNotConfigured;
use App\Domain\Ingestion\Jobs\FetchAttachmentsJob;
use App\Domain\Ingestion\Models\OdkFormSync;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Odk\FieldMap;
use App\Domain\Ingestion\Odk\OdkConfig;
use App\Domain\Ingestion\Odk\SubmissionParser;
use App\Domain\Validation\Actions\MarkSuperseded;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One ODK pull (ARCHITECTURE.md §5.3), resumable and idempotent. The caller holds PullLock.
 *
 * Two passes over the form, each with its own cursor on odk_form_syncs (Q-32, D-51):
 *   new submissions  __system/submissionDate ge last_pulled_at
 *   edits            __system/updatedAt      ge last_edit_at (an edit keeps its submissionDate)
 *
 * Each page commits in ONE transaction: raw rows inserted ON CONFLICT (instance_id) DO NOTHING,
 * the new ones parsed, edits of quarantined submissions superseded (MarkSuperseded), the batch
 * validated and written (ProcessParsedBatch, one ValidationContext), the run's counters, and last
 * the cursor. A crash anywhere in a page rolls all of it back, cursor included, so the next pull
 * replays that page. The cursor is inclusive (ge): rows sharing the last timestamp are fetched again
 * and dropped by instance ID, never lost.
 */
final class PullSubmissions
{
    public function __construct(
        private readonly SubmissionSource $source,
        private readonly SubmissionParser $parser,
        private readonly ProcessParsedBatch $batch,
        private readonly MarkSuperseded $superseded,
        private readonly RecordAttachments $attachments,
    ) {}

    public function execute(PullTrigger $trigger, ?int $userId = null): OdkPullRun
    {
        $run = OdkPullRun::query()->create(['trigger_type' => $trigger, 'triggered_by' => $userId, 'started_at' => now()]);
        if ($trigger === PullTrigger::Manual) {
            // SECURITY.md §4: a manual pull is audited; from the console the causer is empty.
            AuditLog::record($run, $userId === null ? null : User::query()->find($userId), 'pull_requested', old: [], new: ['trigger' => $trigger->value]);
        }

        $config = OdkConfig::fromConfig();
        if (! $config->isConfigured()) {
            return $this->finish($run, PullOutcome::SkippedNotConfigured, (new OdkNotConfigured($config->problems()))->getMessage());
        }

        $sync = OdkFormSync::query()->firstOrCreate(['project_id' => (int) $config->projectId, 'form_id' => (string) $config->formId]);

        try {
            $seen = [];
            $this->pass($run, $sync, 'submissionDate', $seen);
            $this->pass($run, $sync, 'updatedAt', $seen);
        } catch (OdkNotConfigured $e) {
            return $this->finish($run, PullOutcome::SkippedNotConfigured, $e->getMessage());
        } catch (Throwable $e) {
            $this->finish($run, PullOutcome::Failed, self::describe($e));

            throw $e;
        }

        $sync->update(['last_error' => null]);
        $this->finish($run, PullOutcome::Succeeded, null);
        $this->heartbeat();

        return $run;
    }

    /**
     * Pages through one cursor. Each next page starts at the newest timestamp seen (ge); when a
     * whole page shares one timestamp the cursor cannot move, so $skip steps past it instead.
     *
     * @param  'submissionDate'|'updatedAt'  $by
     * @param  array<string, true>  $seen  instance IDs already handled in this run (both passes)
     */
    private function pass(OdkPullRun $run, OdkFormSync $sync, string $by, array &$seen): void
    {
        $column = $by === 'updatedAt' ? 'last_edit_at' : 'last_pulled_at';
        $cursor = $sync->{$column};
        $cursor = $cursor instanceof CarbonImmutable ? $cursor : null;
        $top = max(1, (int) config('edqa.odk.page_size', 500));
        $skip = 0;

        while (true) {
            $page = $this->source->submissionsPage($cursor, $top, $skip, inclusive: $cursor !== null, by: $by);
            if ($page->rows === []) {
                return;
            }

            $fresh = [];
            $newest = null;
            foreach ($page->rows as $row) {
                $time = self::time(FieldMap::get($row, '__system.'.$by));
                if ($time !== null && ($newest === null || $time->greaterThan($newest))) {
                    $newest = $time;
                }
                $id = self::instanceId($row);
                if ($id !== '' && ! isset($seen[$id])) {
                    $seen[$id] = true;
                    $fresh[] = $row;
                }
            }

            $moved = $newest !== null && ($cursor === null || $newest->greaterThan($cursor));
            if ($fresh !== [] || $moved) {
                $this->commitPage($run, $sync, $fresh, $column, $moved ? $newest : null);
            }

            if (! $page->hasMore) {
                return;
            }
            [$cursor, $skip] = $moved ? [$newest, 0] : [$cursor, $skip + count($page->rows)];
        }
    }

    /**
     * One page, one transaction; the cursor is the last statement in it.
     *
     * @param  list<array<array-key, mixed>>  $rows  not seen before in this run
     */
    private function commitPage(OdkPullRun $run, OdkFormSync $sync, array $rows, string $column, ?CarbonImmutable $cursor): void
    {
        // Asked of ODK before the transaction opens: no HTTP while it holds locks.
        $expected = $this->expectedAttachments($rows);

        $withFiles = DB::transaction(function () use ($run, $sync, $rows, $column, $cursor, $expected): array {
            $inserted = $this->insert($rows, (string) $sync->form_id);
            $submissions = $inserted === [] ? [] : Submission::query()->whereIn('id', array_values($inserted))->get()->keyBy('instance_id')->all();

            $batch = [];
            foreach ($rows as $row) {
                $submission = $submissions[self::instanceId($row)] ?? null;
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

            $run->update([
                'fetched' => $run->fetched + count($rows),
                'duplicates' => $run->duplicates + count($rows) - count($inserted),
                'accepted' => $run->accepted + $counts[SubmissionStatus::Accepted->value],
                'flagged' => $run->flagged + $counts[SubmissionStatus::Flagged->value],
                'quarantined' => $run->quarantined + $counts[SubmissionStatus::Quarantined->value],
            ]);

            // Written in the app's zone, as the connection reads it (D-30). Stored to the second: a
            // cursor rounded down only re-fetches a few rows, dropped by instance ID.
            $last = $rows === [] ? null : self::instanceId($rows[array_key_last($rows)]);
            $sync->update(array_filter([
                $column => $cursor?->setTimezone((string) config('app.timezone')),
                'last_instance_id' => $last,
                'submission_count' => $sync->submission_count + count($inserted),
            ], fn (mixed $value): bool => $value !== null));

            // The attachments of the rows this page stored, pending, with their submissions.
            $bySubmission = [];
            foreach ($expected as $instanceId => $refs) {
                if (isset($inserted[$instanceId])) {
                    $bySubmission[$inserted[$instanceId]] = $refs;
                }
            }

            return $this->attachments->execute($bySubmission);
        });

        foreach ($withFiles as $submissionId) {
            FetchAttachmentsJob::dispatch($submissionId);
        }
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
                $candidates[self::instanceId($row)] = self::text($row['__id'] ?? null) ?? self::instanceId($row);
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
            $submitted = self::time(FieldMap::get($row, '__system.submissionDate')) ?? CarbonImmutable::createFromTimestampUTC(0);
            array_push($bindings,
                self::instanceId($row),
                self::text(FieldMap::get($row, 'meta.deprecatedID')),
                $formId,
                self::text(FieldMap::get($row, '__system.formVersion')) ?? '',
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

    private function finish(OdkPullRun $run, PullOutcome $outcome, ?string $error): OdkPullRun
    {
        $run->update(['outcome' => $outcome, 'finished_at' => now(), 'error' => $error]);

        return $run;
    }

    /** Pings the external dead-man's switch; its failure never fails the pull. */
    private function heartbeat(): void
    {
        $url = config('edqa.heartbeats.odk_pull');
        if (! is_string($url) || $url === '') {
            return;
        }
        try {
            Http::timeout(10)->get($url);
        } catch (Throwable $e) {
            Log::warning('ODK pull heartbeat failed', ['error' => $e::class]);
        }
    }

    /**
     * The error for the run's history and the status bar, which administrators see: an ODK error's
     * own message (written to be safe), a database error's SQLSTATE only (its message carries the
     * SQL and its bindings, i.e. payload values), otherwise the first line of the message.
     */
    public static function describe(Throwable $e): string
    {
        $message = match (true) {
            $e instanceof OdkException => $e->getMessage(),
            $e instanceof QueryException => 'SQLSTATE '.(is_string($e->errorInfo[0] ?? null) ? $e->errorInfo[0] : (string) $e->getCode()),
            default => explode("\n", $e->getMessage())[0],
        };

        return mb_substr(class_basename($e).': '.$message, 0, 500);
    }

    /** @param  array<array-key, mixed>  $row */
    public static function instanceId(array $row): string
    {
        return self::text(FieldMap::get($row, 'meta.instanceID')) ?? self::text($row['__id'] ?? null) ?? '';
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
