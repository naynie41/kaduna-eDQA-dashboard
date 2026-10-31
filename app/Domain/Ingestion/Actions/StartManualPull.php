<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\SourceUnavailable;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\Jobs\ReconcileSourceJob;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\SourceRegistry;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\Cache;

/**
 * "Pull now", "Pull all" and "Reconcile" (POST /api/sources/{key}/pull, /api/sources/pull-all,
 * /api/sources/{key}/reconcile, ARCHITECTURE.md §8). The run row is created here, so the page can
 * follow it at once, and the job carries it on.
 *
 * - One at a time per source and kind: if that source's pull (or its sweep) is already running,
 *   its run is returned instead and nothing is queued, so two clicks never queue two.
 * - A source must be configured and enabled (SourceUnavailable otherwise). A key not in config is
 *   refused by the registry (SourceRefused::unknown).
 * - Every request is audited (SECURITY.md §4) with the source, and whether it joined a running run.
 */
final class StartManualPull
{
    public function __construct(
        private readonly SourceRegistry $sources,
    ) {}

    /** @return array{run: PullRun, running: bool} running: an existing run was returned */
    public function execute(User $actor, string $source): array
    {
        return $this->start($actor, $source, PullTrigger::Manual);
    }

    /** @return array{run: PullRun, running: bool} */
    public function reconcile(User $actor, string $source): array
    {
        return $this->start($actor, $source, PullTrigger::Reconcile);
    }

    /**
     * Every configured and enabled source, in config order.
     *
     * @return list<array{source: string, run: PullRun, running: bool}>
     */
    public function all(User $actor): array
    {
        $keys = $this->sources->enabled();
        if ($keys === []) {
            throw SourceUnavailable::noneEnabled();
        }

        return array_map(fn (string $key): array => ['source' => $key, ...$this->execute($actor, $key)], $keys);
    }

    /** @return array{run: PullRun, running: bool} */
    private function start(User $actor, string $source, PullTrigger $trigger): array
    {
        $name = $this->sources->name($source);
        if ($this->sources->problems($source) !== []) {
            throw SourceUnavailable::notConfigured($name);
        }
        if (! in_array($source, $this->sources->enabled(), true)) {
            throw SourceUnavailable::disabled($name);
        }
        $sourceId = $this->sources->id($source);

        // Two requests at the same instant must not both see "nothing running".
        return Cache::lock("pull-request:{$source}", 10)->block(5, function () use ($actor, $source, $sourceId, $trigger): array {
            $running = self::running($sourceId, $trigger === PullTrigger::Reconcile);
            $run = $running ?? PullRun::query()->create([
                'source_id' => $sourceId, 'trigger_type' => $trigger, 'triggered_by' => $actor->id, 'started_at' => now(),
            ]);

            $event = $trigger === PullTrigger::Reconcile ? 'reconcile_requested' : 'pull_requested';
            AuditLog::record($run, $actor, $event, old: [], new: ['trigger' => $trigger->value, 'source' => $source], extra: ['joined_running' => $running !== null]);

            if ($running === null) {
                $trigger === PullTrigger::Reconcile
                    ? ReconcileSourceJob::dispatch($source, $actor->id, $run->id)
                    : PullSubmissionsJob::dispatch($source, PullTrigger::Manual, $actor->id, $run->id);
            }

            return ['run' => $run, 'running' => $running !== null];
        });
    }

    /**
     * The source's run in progress, younger than the pull lock: its reconcile sweep, or else its
     * pull (any trigger but a backfill or a sweep).
     */
    public static function running(int $sourceId, bool $reconcile = false): ?PullRun
    {
        return PullRun::query()
            ->where('source_id', $sourceId)
            ->whereNull('finished_at')
            ->when(
                $reconcile,
                fn ($query) => $query->where('trigger_type', PullTrigger::Reconcile),
                fn ($query) => $query->whereNotIn('trigger_type', [PullTrigger::Backfill, PullTrigger::Reconcile]),
            )
            ->where('started_at', '>=', now()->subSeconds((int) config('edqa.pull.lock_seconds', 900)))
            ->latest('id')
            ->first();
    }
}
