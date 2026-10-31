<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Jobs;

use App\Domain\Ingestion\Actions\BackfillSubmissions;
use App\Domain\Ingestion\BackfillLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The backfill of one source on the queue (ARCHITECTURE.md §5.6), in chunks: each job runs pages
 * for about edqa.backfill.job_seconds, then queues the next for the same source from the
 * checkpoint, so no job outlives the queue's retry_after and a worker is never held for hours. On
 * the low queue: a backfill is never more urgent than a pull. A failed chunk retries from the
 * checkpoint; the run row says where it is. edqa:backfill {source} runs the same backfill in the
 * foreground. One source at a time: BackfillSubmissions refuses another source's backfill while
 * this one's run is open.
 */
final class BackfillSubmissionsJob implements ShouldQueue
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
        public readonly string $source,
        public readonly ?int $fromPage = null,
        public readonly ?int $throttle = null,
        public readonly ?int $runId = null,
    ) {
        $this->onQueue('low');
    }

    public function handle(BackfillSubmissions $backfill, BackfillLock $lock): void
    {
        $seconds = (int) config('edqa.backfill.job_seconds', 300);
        $progress = $lock->run(fn () => $backfill->execute($this->source, $this->fromPage, $this->throttle, $seconds, $this->runId), $this->timeout);

        if (! $progress->complete) {
            self::dispatch($this->source, null, $this->throttle, $progress->run->id);
        }
    }
}
