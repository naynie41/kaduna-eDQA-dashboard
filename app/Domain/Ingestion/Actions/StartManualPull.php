<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\Cache;

/**
 * "Pull now" (POST /api/odk/pull, ARCHITECTURE.md §8): the run row is created here, so the page can
 * follow it at once, and PullSubmissionsJob carries it on. If a pull is already running (an open
 * run, not a backfill, started within the lock's lifetime) its run is returned instead and nothing
 * is queued: one pull at a time, and two clicks never queue two. Every request is audited
 * (SECURITY.md §4), with whether it joined a running pull.
 */
final class StartManualPull
{
    /** @return array{run: OdkPullRun, running: bool} running: an existing pull was returned */
    public function execute(User $actor): array
    {
        // Two requests at the same instant must not both see "nothing running".
        return Cache::lock('odk-pull-request', 10)->block(5, function () use ($actor): array {
            $running = self::running();
            $run = $running ?? OdkPullRun::query()->create([
                'trigger_type' => PullTrigger::Manual, 'triggered_by' => $actor->id, 'started_at' => now(),
            ]);

            AuditLog::record($run, $actor, 'pull_requested', old: [], new: ['trigger' => PullTrigger::Manual->value], extra: ['joined_running' => $running !== null]);

            if ($running === null) {
                PullSubmissionsJob::dispatch(PullTrigger::Manual, $actor->id, $run->id);
            }

            return ['run' => $run, 'running' => $running !== null];
        });
    }

    /** The pull in progress: an open run, not a backfill, younger than the pull lock. */
    public static function running(): ?OdkPullRun
    {
        return OdkPullRun::query()
            ->whereNull('finished_at')
            ->where('trigger_type', '!=', PullTrigger::Backfill)
            ->where('started_at', '>=', now()->subSeconds((int) config('edqa.odk.pull.lock_seconds', 900)))
            ->latest('id')
            ->first();
    }
}
