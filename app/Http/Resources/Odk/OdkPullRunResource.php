<?php

declare(strict_types=1);

namespace App\Http\Resources\Odk;

use App\Domain\Ingestion\Models\PullRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One pull's progress (GET /api/odk/pull/{runId}): its counters as each page commits, then its
 * outcome. status is running until it finishes: succeeded, failed or skipped_not_configured.
 * error is the run's own safe description (PullSubmissions::describe), never a payload.
 *
 * @mixin PullRun
 */
final class OdkPullRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'run_id' => $this->id,
            'trigger' => $this->trigger_type->value,
            'status' => $this->outcome->value ?? 'running',
            'started_at' => $this->started_at->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'fetched' => $this->fetched,
            'parsed' => $this->parsed,
            'duplicates' => $this->duplicates,
            'accepted' => $this->accepted,
            'flagged' => $this->flagged,
            'quarantined' => $this->quarantined,
            'error' => $this->error,
        ];
    }
}
