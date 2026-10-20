<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Jobs;

use App\Domain\Ingestion\Actions\BackfillSubmissions;
use App\Domain\Ingestion\Odk\BackfillLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The backfill on the queue (ARCHITECTURE.md §5.6), in chunks: each job runs pages for about
 * edqa.odk.backfill.job_seconds, then queues the next from the checkpoint, so no job outlives the
 * queue's retry_after and a worker is never held for hours. On the low queue: a backfill is never
 * more urgent than a pull. A failed chunk retries from the checkpoint; the run row says where it is.
 * edqa:backfill runs the same backfill in the foreground.
 */
final class BackfillOdkSubmissionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /** One chunk, plus a wait for the pull lock on its last page. */
    public int $timeout = 900;

    public function __construct(
        public readonly ?int $fromPage = null,
        public readonly ?int $throttle = null,
        public readonly ?int $runId = null,
    ) {
        $this->onQueue('low');
    }

    public function handle(BackfillSubmissions $backfill, BackfillLock $lock): void
    {
        $seconds = (int) config('edqa.odk.backfill.job_seconds', 300);
        $progress = $lock->run(fn () => $backfill->execute($this->fromPage, $this->throttle, $seconds, $this->runId), $this->timeout);

        if (! $progress->complete) {
            self::dispatch(null, $this->throttle, $progress->run->id);
        }
    }
}
