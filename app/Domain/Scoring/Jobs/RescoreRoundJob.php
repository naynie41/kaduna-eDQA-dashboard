<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Jobs;

use App\Domain\Scoring\Actions\RescoreAssessments;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One chunk (up to edqa.rescore.chunk_size assessments) of a RescoreRound batch. Idempotent: the
 * chunk is one transaction, its upsert writes only changed rows, and the run counts it once, so
 * a retry or a second delivery never duplicates anything.
 */
final class RescoreRoundJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 120;

    /** @param  list<int>  $assessmentIds */
    public function __construct(
        public readonly int $runId,
        public readonly int $chunk,
        public readonly array $assessmentIds,
    ) {}

    public function handle(RescoreAssessments $rescore): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $rescore->execute($this->runId, $this->chunk, $this->assessmentIds);
    }

    public function failed(?Throwable $e): void
    {
        Log::channel('edqa')->error('rescore.chunk_failed', [
            'run_id' => $this->runId,
            'chunk' => $this->chunk,
            'error' => $e?->getMessage(),
        ]);
    }
}
