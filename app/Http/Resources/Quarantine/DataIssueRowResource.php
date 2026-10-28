<?php

declare(strict_types=1);

namespace App\Http\Resources\Quarantine;

use App\Domain\Ingestion\Odk\CentralLink;
use App\Domain\Validation\Actions\CorrectAndReprocess;
use App\Domain\Validation\Models\QuarantinedRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Data issues row (D-57): the instance ID, every failure (code, severity, name, detail), the
 * facility reference with the master list's name when it matches, LGA and round, when it was
 * received, its state, and the current value of each correctable field (the parsed snapshot
 * with the corrections laid over it), which the correct form starts from. Never the payload:
 * the detail endpoint has it. Expects submission and round loaded.
 *
 * @mixin QuarantinedRecord
 */
final class DataIssueRowResource extends JsonResource
{
    /** @param  array<string, string>  $facilityNames  lower-cased ref => name (QuarantineQuery::facilityNames) */
    public function __construct(QuarantinedRecord $record, private readonly array $facilityNames = [])
    {
        parent::__construct($record);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $parsed = is_array($this->parsed) ? $this->parsed : [];
        $current = [];
        foreach (array_keys(CorrectAndReprocess::CORRECTABLE) as $field) {
            $current[$field] = array_key_exists($field, $this->corrections) ? $this->corrections[$field] : ($parsed[$field] ?? null);
        }
        $facilityRef = is_string($this->facility_ref) ? $this->facility_ref : null;

        return [
            'id' => $this->id,
            'instance_id' => $this->instance_id,
            'submission_id' => $this->submission_id,
            'failures' => array_map(fn (array $failure): array => [
                'code' => (string) $failure['code'],
                'severity' => (string) ($failure['severity'] ?? 'hard'),
                'name' => (string) __('validation_rules.'.$failure['code'].'.name'),
                'detail' => (string) ($failure['detail'] ?? ''),
                'field' => $failure['field'] ?? null,
            ], $this->failures),
            'facility_ref' => $facilityRef,
            'facility_name' => $facilityRef === null ? null : ($this->facilityNames[mb_strtolower(trim($facilityRef))] ?? null),
            'lga_ref' => $this->lga_ref,
            'round' => $this->round === null ? null : ['id' => $this->round->id, 'year' => $this->round->year, 'quarter' => $this->round->quarter],
            'received_at' => $this->submission?->received_at?->toIso8601String(),
            'status' => $this->status->value,
            'resolution' => $this->resolution?->value,
            'resolution_note' => $this->resolution_note,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'corrections' => (object) $this->corrections,
            'current' => $current,
            'odk_url' => $this->submission === null ? null : CentralLink::submission((string) $this->submission->form_ref, (string) $this->instance_id),
        ];
    }
}
