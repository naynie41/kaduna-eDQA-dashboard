<?php

declare(strict_types=1);

namespace App\Http\Resources\Scoring;

use App\Domain\Scoring\Models\RescoreRun;
use Illuminate\Bus\Batch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A rescore and its progress: chunks from the queue's batch (while job_batches still holds it),
 * assessments and outcomes from the rescore_runs row, which the admin page keeps as history.
 *
 * @mixin RescoreRun
 */
final class RescoreRunResource extends JsonResource
{
    public function __construct(RescoreRun $run, private readonly ?Batch $batch = null)
    {
        parent::__construct($run);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'run_id' => $this->id,
            'batch_id' => $this->batch_id,
            'round_id' => $this->round_id,
            'rule_version_id' => $this->rule_version_id,
            'trigger' => $this->trigger->value,
            'reason' => $this->reason,
            'requested_by' => $this->requested_by,
            'status' => $this->status->value,
            'progress' => $this->batch?->progress(),
            'chunks' => $this->batch === null ? null : [
                'total' => $this->batch->totalJobs,
                'processed' => $this->batch->processedJobs(),
                'failed' => $this->batch->failedJobs,
            ],
            'assessments' => [
                'total' => $this->total_assessments,
                'processed' => $this->processed_assessments,
                'unscorable' => $this->unscorable,
            ],
            'changed_scores' => $this->changed_scores,
            'changed_flags' => $this->changed_flags,
            'failed_chunks' => $this->failed_chunks,
            'started_at' => $this->started_at->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
