<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Jobs;

use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Actions\ReconcileSource;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\PullLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * The reconcile sweep of one source on the queue (ARCHITECTURE.md §5.3, D-61, D-62): nightly per
 * configured and enabled source, for the open round, and on demand (edqa:reconcile {source}). It
 * writes the same submissions a pull does, so it runs under the source's PullLock: while a pull of
 * that source runs it waits, then goes back on the queue.
 */
final class ReconcileSourceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 900;

    public function __construct(
        public readonly string $source,
        public readonly ?int $userId = null,
        public readonly ?int $runId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(PullLock $lock, ReconcileSource $reconcile): void
    {
        try {
            $lock->run($this->source, fn (): PullRun => $reconcile->execute($this->source, $this->userId, $this->runId), PullLock::waitSeconds());
        } catch (LockTimeoutException) {
            $this->release(60);
        }
    }

    public function failed(?Throwable $e): void
    {
        // A run created when the sweep was asked for, never reached (the lock stayed busy): close it.
        if ($this->runId !== null) {
            PullRun::query()->whereKey($this->runId)->whereNull('finished_at')->update([
                'outcome' => PullOutcome::Failed, 'finished_at' => now(),
                'error' => $e === null ? 'Unknown failure' : PullSubmissions::describe($e),
            ]);
        }
    }
}
