<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Actions;

use App\Domain\Reporting\Jobs\RefreshRoundAggregatesJob;
use App\Domain\Reporting\RoundCache;
use App\Domain\Scoring\Enums\RescoreStatus;
use App\Domain\Scoring\Models\RescoreRun;
use Illuminate\Bus\Batch;

/**
 * Closes a rescore run from its batch, then publishes what it wrote: refreshes round_aggregates
 * (debounced) and flushes the round's cache. Called by the batch's finally callback (every chunk
 * has run once; failed while any failed) and its then callback (every chunk has succeeded,
 * including after a retry), so it is idempotent. What the successful chunks wrote is published
 * either way: each chunk is a whole transaction, never a partial assessment.
 */
final class FinishRescore
{
    public function __construct(
        private readonly RoundCache $cache,
    ) {}

    public function execute(int $runId, Batch $batch): void
    {
        $run = RescoreRun::query()->find($runId);
        if ($run === null) {
            return;
        }

        $succeeded = $batch->pendingJobs === 0;
        $run->update([
            'status' => $succeeded ? RescoreStatus::Succeeded : RescoreStatus::Failed,
            'failed_chunks' => $succeeded ? 0 : count($batch->failedJobIds),
            'finished_at' => now(),
        ]);

        RefreshRoundAggregatesJob::debounced($run->round_id);
        $this->cache->flushRound($run->round_id);
    }
}
