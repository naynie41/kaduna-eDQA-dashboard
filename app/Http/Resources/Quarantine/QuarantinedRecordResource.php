<?php

declare(strict_types=1);

namespace App\Http\Resources\Quarantine;

use App\Domain\Validation\Models\QuarantinedRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Data issues row. Never the raw payload or the parsed snapshot: the corrections and the
 * named failures are enough to act on.
 *
 * @mixin QuarantinedRecord
 */
final class QuarantinedRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'instance_id' => $this->instance_id,
            'submission_id' => $this->submission_id,
            'round_id' => $this->round_id,
            'facility_ref' => $this->facility_ref,
            'lga_ref' => $this->lga_ref,
            'status' => $this->status->value,
            'resolution' => $this->resolution?->value,
            'resolution_note' => $this->resolution_note,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'corrections' => (object) $this->corrections,
            'failures' => array_map(fn (array $failure): array => [
                ...$failure,
                'name' => __('validation_rules.'.$failure['code'].'.name'),
            ], $this->failures),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
