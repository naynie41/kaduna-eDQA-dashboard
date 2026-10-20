<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\BackfillProgress;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\BackfillRefused;
use App\Domain\Ingestion\Exceptions\OdkNotConfigured;
use App\Domain\Ingestion\Models\OdkFormSync;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Domain\Ingestion\Odk\FieldMap;
use App\Domain\Ingestion\Odk\OdkConfig;
use App\Domain\Ingestion\Odk\OdkRow;
use App\Domain\Ingestion\Odk\PullLock;
use App\Domain\Round\Models\Round;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The historical backfill (ARCHITECTURE.md §5.6, D-53): every submission the form has, oldest
 * first, through the same page path as the pull (ProcessSubmissionsPage), so the same parser,
 * validation and scoring decide every row.
 *
 * - The feed is read from the start ($skip, ordered by submissionDate, no filter); the position
 *   after each committed page is checkpointed into odk_form_syncs.backfill_skip in that page's
 *   transaction, so a crash replays only the page it was in, and a restart resumes there.
 * - Each page commits under PullLock, so it never runs at the same time as a pull's page; between
 *   pages the scheduled pull runs as usual. Rows a pull already stored are duplicates.
 * - Attachments are recorded, not fetched (edqa:attachments:fetch-pending catches up).
 * - last_pulled_at only moves forward (GREATEST), so the first pull after a backfill does not
 *   re-read history.
 * - It refuses to start until some historical round exists: a visit with no round quarantines
 *   under ROUND_WINDOW, never guessed into the nearest round (hard rule 12).
 *
 * Progress is one odk_pull_runs row, trigger backfill; a backfill run in chunks (the job) keeps
 * the same row until the end.
 */
final class BackfillSubmissions
{
    public function __construct(
        private readonly SubmissionSource $source,
        private readonly ProcessSubmissionsPage $page,
        private readonly PullLock $lock,
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
     * @param  int|null  $throttle  seconds between pages (default edqa.odk.backfill.throttle_seconds)
     * @param  int|null  $maxSeconds  stop after the page that passes this (the job's chunks); null: to the end
     * @param  int|null  $runId  the run to carry on (a chunk after the first)
     * @param  (Closure(BackfillProgress): void)|null  $progress  after each committed page
     *
     * @throws BackfillRefused
     */
    public function execute(?int $fromPage = null, ?int $throttle = null, ?int $maxSeconds = null, ?int $runId = null, ?Closure $progress = null): BackfillProgress
    {
        if (! self::historicalRoundsExist()) {
            throw BackfillRefused::noHistoricalRounds();
        }

        $run = $runId === null ? null : OdkPullRun::query()->whereKey($runId)->whereNull('finished_at')->first();
        if ($run === null) {
            $run = OdkPullRun::query()->create(['trigger_type' => PullTrigger::Backfill, 'started_at' => now()]);
            // SECURITY.md §4: started by an administrator, from the console.
            AuditLog::record($run, null, 'backfill_started', old: [], new: ['from_page' => $fromPage, 'throttle' => $throttle]);
        }

        $config = OdkConfig::fromConfig();
        if (! $config->isConfigured()) {
            $this->finish($run, PullOutcome::SkippedNotConfigured, (new OdkNotConfigured($config->problems()))->getMessage());

            return new BackfillProgress($run, 0, 0, 0, true);
        }

        $sync = OdkFormSync::query()->firstOrCreate(['project_id' => (int) $config->projectId, 'form_id' => (string) $config->formId]);
        $top = max(1, (int) config('edqa.odk.page_size', 500));
        $throttle = max(0, $throttle ?? (int) config('edqa.odk.backfill.throttle_seconds', 5));
        $skip = $fromPage !== null ? max(0, $fromPage) * $top : (int) $sync->backfill_skip;
        $wait = (int) config('edqa.odk.pull.lock_wait_seconds', 600);
        $started = microtime(true);
        $total = 0;

        try {
            while (true) {
                $page = $this->source->submissionsPage(null, $top, $skip);
                $total = $page->totalCount;
                if ($page->rows === []) {
                    break;
                }

                $newest = null;
                foreach ($page->rows as $row) {
                    $time = OdkRow::time(FieldMap::get($row, '__system.submissionDate'));
                    $newest = $time !== null && ($newest === null || $time->greaterThan($newest)) ? $time : $newest;
                }
                $next = $skip + count($page->rows);
                $this->lock->run(fn () => $this->page->execute($run, $sync, $page->rows, [
                    'backfill_skip' => $next,
                    'last_pulled_at' => $newest,
                ], fetchAttachments: false), $wait);
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

    private function finish(OdkPullRun $run, PullOutcome $outcome, ?string $error): void
    {
        $run->update(['outcome' => $outcome, 'finished_at' => now(), 'error' => $error]);
    }
}
