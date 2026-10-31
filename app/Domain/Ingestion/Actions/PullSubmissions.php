<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\SubmissionMeta;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\SourceException;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\SourceRegistry;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One pull from one source (ARCHITECTURE.md §5.3), resumable and idempotent. The caller holds the
 * source's PullLock; another source may be pulling at the same time.
 *
 * Two passes over the source's form, each with its own cursor on its source_syncs row (Q-32, D-51):
 *   new submissions  received at or after cursor_at (submissionsPage)
 *   edits            edited at or after edit_cursor_at (changedSince: ODK's __system/updatedAt;
 *                    none on Kobo, whose edits only the reconcile sweep finds)
 *
 * Each page commits in ONE transaction through ProcessSubmissionsPage, the path the backfill and
 * the sweep take too, with the cursor last. A crash anywhere in a page rolls all of it back, cursor
 * included, so the next pull replays that page. The cursor is inclusive: rows sharing the last
 * timestamp are fetched again and dropped by instance ID, never lost. The times and IDs are read
 * through the source's normaliser, so this class knows no source's shape.
 */
final class PullSubmissions
{
    public function __construct(
        private readonly ProcessSubmissionsPage $page,
        private readonly SourceRegistry $sources,
    ) {}

    /** @param  int|null  $runId  a run row created when the pull was asked for (StartManualPull), still open */
    public function execute(string $source, PullTrigger $trigger = PullTrigger::Scheduled, ?int $userId = null, ?int $runId = null): PullRun
    {
        $sourceId = $this->sources->id($source);
        $run = $runId === null ? null : PullRun::query()->whereKey($runId)->whereNull('finished_at')->first();
        if ($run !== null) {
            $run->update(['started_at' => now()]); // asked for earlier: the pull starts now
        } else {
            $run = PullRun::query()->create(['source_id' => $sourceId, 'trigger_type' => $trigger, 'triggered_by' => $userId, 'started_at' => now()]);
        }
        if ($trigger === PullTrigger::Manual && $runId === null) {
            // SECURITY.md §4: a manual pull is audited; from the console the causer is empty.
            AuditLog::record($run, $userId === null ? null : User::query()->find($userId), 'pull_requested', old: [], new: ['trigger' => $trigger->value, 'source' => $source]);
        }

        if ($this->sources->problems($source) !== []) {
            return $this->finish($run, PullOutcome::SkippedNotConfigured, $this->sources->notConfigured($source)->getMessage());
        }

        $sync = SourceSync::forForm($sourceId, $this->sources->formRef($source));
        $adapter = $this->sources->adapter($source);

        try {
            $seen = [];
            $this->pass($source, $adapter, $run, $sync, 'received', $seen);
            $this->pass($source, $adapter, $run, $sync, 'edited', $seen);
        } catch (SourceException $e) {
            if ($e->status() === 'not_configured') {
                return $this->finish($run, PullOutcome::SkippedNotConfigured, $e->getMessage());
            }
            $this->finish($run, PullOutcome::Failed, self::describe($e));

            throw $e;
        } catch (Throwable $e) {
            $this->finish($run, PullOutcome::Failed, self::describe($e));

            throw $e;
        }

        $sync->update(['last_error' => null, 'last_success_at' => now()]);
        $this->finish($run, PullOutcome::Succeeded, null);
        $this->heartbeat($source);

        return $run;
    }

    /**
     * Pages through one cursor. Each next page starts at the newest time seen (inclusive); when a
     * whole page shares one time the cursor cannot move, so $skip steps past it instead.
     *
     * @param  'received'|'edited'  $by
     * @param  array<string, true>  $seen  instance IDs already handled in this run (both passes)
     */
    private function pass(string $source, SubmissionSource $adapter, PullRun $run, SourceSync $sync, string $by, array &$seen): void
    {
        $column = $by === 'edited' ? 'edit_cursor_at' : 'cursor_at';
        $cursor = $sync->{$column};
        $cursor = $cursor instanceof CarbonImmutable ? $cursor : null;
        $top = $this->sources->pageSize($source);
        $normaliser = $this->sources->normaliser($source);
        $skip = 0;

        while (true) {
            $page = $by === 'edited'
                ? $adapter->changedSince($cursor, $top, $skip)
                : $adapter->submissionsPage($cursor, $top, $skip, inclusive: $cursor !== null);
            if ($page->rows === []) {
                return;
            }

            $fresh = [];
            $newest = null;
            foreach ($page->rows as $row) {
                $meta = $normaliser->normalise($row)->meta;
                $time = self::time($meta, $by);
                if ($time !== null && ($newest === null || $time->greaterThan($newest))) {
                    $newest = $time;
                }
                if ($meta->instanceId !== '' && ! isset($seen[$meta->instanceId])) {
                    $seen[$meta->instanceId] = true;
                    $fresh[] = $row;
                }
            }

            $moved = $newest !== null && ($cursor === null || $newest->greaterThan($cursor));
            if ($fresh !== [] || $moved) {
                $this->page->execute($run, $sync, $fresh, [$column => $moved ? $newest : null]);
            }

            if (! $page->hasMore) {
                return;
            }
            [$cursor, $skip] = $moved ? [$newest, 0] : [$cursor, $skip + count($page->rows)];
        }
    }

    /** @param  'received'|'edited'  $by */
    private static function time(SubmissionMeta $meta, string $by): ?CarbonImmutable
    {
        return $by === 'edited' ? $meta->updatedAt : $meta->submittedAt;
    }

    private function finish(PullRun $run, PullOutcome $outcome, ?string $error): PullRun
    {
        $run->update(['outcome' => $outcome, 'finished_at' => now(), 'error' => $error]);

        return $run;
    }

    /** Pings the source's external dead-man's switch; its failure never fails the pull. */
    private function heartbeat(string $source): void
    {
        $url = config("edqa.heartbeats.pull.{$source}");
        if (! is_string($url) || $url === '') {
            return;
        }
        try {
            Http::timeout(10)->get($url);
        } catch (Throwable $e) {
            Log::warning('Pull heartbeat failed', ['source' => $source, 'error' => $e::class]);
        }
    }

    /**
     * The error for the run's history and the status bar, which administrators see: a source
     * error's own message (written to be safe), a database error's SQLSTATE only (its message
     * carries the SQL and its bindings, i.e. payload values), otherwise the first line of the
     * message.
     */
    public static function describe(Throwable $e): string
    {
        $message = match (true) {
            $e instanceof SourceException => $e->getMessage(),
            $e instanceof QueryException => 'SQLSTATE '.(is_string($e->errorInfo[0] ?? null) ? $e->errorInfo[0] : (string) $e->getCode()),
            default => explode("\n", $e->getMessage())[0],
        };

        return mb_substr(class_basename($e).': '.$message, 0, 500);
    }
}
