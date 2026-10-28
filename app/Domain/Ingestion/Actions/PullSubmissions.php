<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\OdkException;
use App\Domain\Ingestion\Exceptions\OdkNotConfigured;
use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\Odk\OdkConfig;
use App\Domain\Ingestion\Odk\OdkRow;
use App\Domain\Ingestion\SourceRegistry;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One ODK pull (ARCHITECTURE.md §5.3), resumable and idempotent. The caller holds PullLock.
 *
 * Two passes over the form, each with its own cursor on source_syncs (Q-32, D-51):
 *   new submissions  __system/submissionDate ge cursor_at
 *   edits            __system/updatedAt      ge edit_cursor_at (an edit keeps its submissionDate)
 *
 * Each page commits in ONE transaction through ProcessSubmissionsPage, the path the backfill takes
 * too, with the cursor last. A crash anywhere in a page rolls all of it back, cursor included, so
 * the next pull replays that page. The cursor is inclusive (ge): rows sharing the last timestamp are fetched again
 * and dropped by instance ID, never lost.
 */
final class PullSubmissions
{
    public function __construct(
        private readonly SubmissionSource $source,
        private readonly ProcessSubmissionsPage $page,
        private readonly SourceRegistry $sources,
    ) {}

    /** @param  int|null  $runId  a run row created when the pull was asked for (StartManualPull), still open */
    public function execute(PullTrigger $trigger, ?int $userId = null, ?int $runId = null): PullRun
    {
        $run = $runId === null ? null : PullRun::query()->whereKey($runId)->whereNull('finished_at')->first();
        if ($run !== null) {
            $run->update(['started_at' => now()]); // asked for earlier: the pull starts now
        } else {
            $run = PullRun::query()->create(['source_id' => $this->sources->id('odk'), 'trigger_type' => $trigger, 'triggered_by' => $userId, 'started_at' => now()]);
        }
        if ($trigger === PullTrigger::Manual && $runId === null) {
            // SECURITY.md §4: a manual pull is audited; from the console the causer is empty.
            AuditLog::record($run, $userId === null ? null : User::query()->find($userId), 'pull_requested', old: [], new: ['trigger' => $trigger->value]);
        }

        $config = OdkConfig::fromConfig();
        if (! $config->isConfigured()) {
            return $this->finish($run, PullOutcome::SkippedNotConfigured, (new OdkNotConfigured($config->problems()))->getMessage());
        }

        $sync = SourceSync::forForm($this->sources->id('odk'), $config->formRef());

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

        $sync->update(['last_error' => null, 'last_success_at' => now()]);
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
    private function pass(PullRun $run, SourceSync $sync, string $by, array &$seen): void
    {
        $column = $by === 'updatedAt' ? 'edit_cursor_at' : 'cursor_at';
        $cursor = $sync->{$column};
        $cursor = $cursor instanceof CarbonImmutable ? $cursor : null;
        $top = max(1, (int) config('edqa.sources.odk.page_size', 500));
        $skip = 0;

        while (true) {
            $page = $by === 'updatedAt'
                ? $this->source->changedSince($cursor, $top, $skip)
                : $this->source->submissionsPage($cursor, $top, $skip, inclusive: $cursor !== null);
            if ($page->rows === []) {
                return;
            }

            $fresh = [];
            $newest = null;
            foreach ($page->rows as $row) {
                $time = OdkRow::time(FieldMap::get($row, '__system.'.$by));
                if ($time !== null && ($newest === null || $time->greaterThan($newest))) {
                    $newest = $time;
                }
                $id = OdkRow::instanceId($row);
                if ($id !== '' && ! isset($seen[$id])) {
                    $seen[$id] = true;
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

    private function finish(PullRun $run, PullOutcome $outcome, ?string $error): PullRun
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
}
