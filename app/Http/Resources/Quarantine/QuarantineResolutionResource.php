<?php

declare(strict_types=1);

namespace App\Http\Resources\Quarantine;

use App\Domain\Validation\Models\QuarantinedRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of resolving one record: the record's own state plus what became of its
 * submission (accepted, flagged, still quarantined, rejected) and the assessment if one exists.
 * Expects submission.assessment loaded.
 *
 * @mixin QuarantinedRecord
 */
final class QuarantineResolutionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...(new QuarantinedRecordResource($this->resource))->toArray($request),
            'quarantined_record_id' => $this->id,
            'record_status' => $this->status->value,
            'status' => $this->submission->status->value,
            'assessment_id' => $this->submission->assessment?->id,
            // A resolution in a round queues that round's rescore (RescoreAfterQuarantineResolved, debounced).
            'rescore_round' => $this->status->value === 'resolved' && $this->round !== null
                ? ['id' => $this->round->id, 'year' => $this->round->year, 'quarter' => $this->round->quarter]
                : null,
        ];
    }
}
