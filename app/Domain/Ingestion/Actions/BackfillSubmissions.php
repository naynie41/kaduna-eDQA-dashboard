<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\DTOs\BackfillProgress;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\BackfillRefused;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\PullLock;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Round\Models\Round;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The historical backfill of one source (ARCHITECTURE.md §5.6, D-53): every submission its form
 * has, oldest first, through the same page path as the pull (ProcessSubmissionsPage), so the same
 * parser, validation and scoring decide every row, whichever source it came from.
 *
 * - One source at a time: refused while another source's backfill run is still open (the run of
 *   a backfill chunked on the queue stays open between chunks; one that neither started nor
 *   committed a page, its sync row's updated_at, within edqa.backfill.stale_after_seconds is
 *   taken as left by a crash). BackfillLock keeps two foreground runs apart.
 * - The feed is read from the start ($skip, ordered by submission time, no filter); the position
 *   after each committed page is checkpointed into the source's source_syncs.backfill_checkpoint
 *   in that page's transaction, so a crash replays only the page it was in, and a restart resumes
 *   there.
 * - Each page commits under the source's PullLock, so it never runs at the same time as a pull's
 *   page of that source; between pages the scheduled pull runs as usual. Rows a pull already
 *   stored are duplicates; rows the other source holds are cross-source duplicates (D-60).
 * - Attachments are recorded, not fetched (edqa:attachments:fetch-pending catches up).
 * - cursor_at only moves forward (GREATEST), so the first pull after a backfill does not
 *   re-read history.
 * - It refuses to start until some historical round exists: a visit with no round quarantines
 *   under ROUND_WINDOW, never guessed into the nearest round (hard rule 12).
 *
 * Progress is one pull_runs row of the source, trigger backfill; a backfill run in chunks (the
 * job) keeps the same row until the end.
 */
final class BackfillSubmissions
{
    public function __construct(
        private readonly ProcessSubmissionsPage $page,
        private readonly PullLock $lock,
        private readonly SourceRegistry $sources,
    ) {}

    /** Some round before the current quarter exists. */
    public static function historicalRoundsExist(): bool
    {
        $now = CarbonImmutable::now((string) config('app.timezone'));

        return Round::query()
            ->where(fn ($q) => $q->where('year', '<', $now->year)->orWhere(fn ($q) => $q->where('year', $now->year)->where('quarter', '<', $now->quarter)))
            ->exists();
    }

    /**
     * @param  int|null  $fromPage  0-based page to start at instead of the checkpoint
     * @param  int|null  $throttle  seconds between pages (default the source's backfill_throttle_seconds)
     * @param  int|null  $maxSeconds  stop after the page that passes this (the job's chunks); null: to the end
     * @param  int|null  $runId  the run to carry on (a chunk after the first)
     * @param  (Closure(BackfillProgress): void)|null  $progress  after each committed page
     *
     * @throws BackfillRefused
     */
    public function execute(string $source, ?int $fromPage = null, ?int $throttle = null, ?int $maxSeconds = null, ?int $runId = null, ?Closure $progress = null): BackfillProgress
    {
        $sourceId = $this->sources->id($source);
        if (! self::historicalRoundsExist()) {
            throw BackfillRefused::noHistoricalRounds();
        }
        $other = $this->otherSourceRunning($sourceId);
        if ($other !== null) {
            throw BackfillRefused::otherSourceRunning($this->sources->name($other));
        }

        $run = $runId === null ? null : PullRun::query()->whereKey($runId)->where('source_id', $sourceId)->whereNull('finished_at')->first();
        if ($run === null) {
            $run = PullRun::query()->create(['source_id' => $sourceId, 'trigger_type' => PullTrigger::Backfill, 'started_at' => now()]);
            // SECURITY.md §4: started by an administrator, from the console.
            AuditLog::record($run, null, 'backfill_started', old: [], new: ['source' => $source, 'from_page' => $fromPage, 'throttle' => $throttle]);
        }

        if ($this->sources->problems($source) !== []) {
            $this->finish($run, PullOutcome::SkippedNotConfigured, $this->sources->notConfigured($source)->getMessage());

            return new BackfillProgress($run, 0, 0, 0, true);
        }

        $adapter = $this->sources->adapter($source);
        $normaliser = $this->sources->normaliser($source);
        $sync = SourceSync::forForm($sourceId, $this->sources->formRef($source));
        $top = $this->sources->pageSize($source);
        $throttle = max(0, $throttle ?? $this->sources->backfillThrottle($source));
        $skip = $fromPage !== null ? max(0, $fromPage) * $top : (int) $sync->backfill_checkpoint;
        $started = microtime(true);
        $total = 0;

        try {
            while (true) {
                $page = $adapter->submissionsPage(null, $top, $skip);
                $total = $page->totalCount;
                if ($page->rows === []) {
                    break;
                }

                $newest = null;
                foreach ($page->rows as $row) {
                    $time = $normaliser->normalise($row)->meta->submittedAt;
                    $newest = $time !== null && ($newest === null || $time->greaterThan($newest)) ? $time : $newest;
                }
                $next = $skip + count($page->rows);
                $this->lock->run($source, fn () => $this->page->execute($run, $sync, $page->rows, [
                    'backfill_checkpoint' => $next,
                    'cursor_at' => $newest,
                ], fetchAttachments: false), PullLock::waitSeconds());
                $skip = $next;

                $done = ! $page->hasMore;
                $current = new BackfillProgress($run->refresh(), intdiv($skip - 1, $top), $skip, $total, $done);
                if ($progress !== null) {
                    $progress($current);
                }
                if ($done) {
                    break;
                }
                if ($maxSeconds !== null && microtime(true) - $started >= $maxSeconds) {
                    return $current;
                }
                if ($throttle > 0) {
                    Sleep::for($throttle)->seconds();
                }
            }
        } catch (Throwable $e) {
            $this->finish($run, PullOutcome::Failed, PullSubmissions::describe($e));

            throw $e;
        }

        $this->finish($run, PullOutcome::Succeeded, null);

        return new BackfillProgress($run, intdiv(max(0, $skip - 1), $top), $skip, $total, true);
    }

    /**
     * The key of another source whose backfill run is open and alive: started, or a page of that
     * source committed (its sync row's updated_at), within the stale window. Null when none.
     */
    private function otherSourceRunning(int $sourceId): ?string
    {
        $since = now()->subSeconds((int) config('edqa.backfill.stale_after_seconds', 3600));
        $key = PullRun::query()
            ->join('submission_sources as src', 'src.id', '=', 'pull_runs.source_id')
            ->leftJoin('source_syncs as sync', 'sync.source_id', '=', 'pull_runs.source_id')
            ->where('pull_runs.trigger_type', PullTrigger::Backfill)
            ->where('pull_runs.source_id', '<>', $sourceId)
            ->whereNull('pull_runs.finished_at')
            ->where(fn ($q) => $q->where('pull_runs.started_at', '>=', $since)->orWhere('sync.updated_at', '>=', $since))
            ->orderByDesc('pull_runs.id')
            ->value('src.key');

        return is_string($key) ? $key : null;
    }

    private function finish(PullRun $run, PullOutcome $outcome, ?string $error): void
    {
        $run->update(['outcome' => $outcome, 'finished_at' => now(), 'error' => $error]);
    }
}
