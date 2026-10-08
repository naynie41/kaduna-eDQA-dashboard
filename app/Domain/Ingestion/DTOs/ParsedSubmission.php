<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

use App\Support\Data\ArrayReader;
use Carbon\CarbonImmutable;

/**
 * A submission as the parser reads it through its form version's field map (ARCHITECTURE.md
 * §5.5), before any validation. References (LGA, ward, facility) are the raw submitted values,
 * not resolved IDs: resolving them is the validation rules' job.
 *
 * Unparseable dates are null in startedAt/endedAt; rawStart/rawEnd keep what ODK sent, so
 * COLUMN_DRIFT can name the bad value. rawNumericScores is set only by legacy numeric-entry
 * forms, keyed "availability_m1" etc.; it is checked (SCORE_RANGE) but never used as a score.
 */
final readonly class ParsedSubmission
{
    /**
     * @param  array<string, float>|null  $gps  lat, lng and accuracy
     * @param  list<ItemResponseData>  $items
     * @param  array<string, float>|null  $rawNumericScores
     */
    public function __construct(
        public string $instanceId,
        public ?string $deprecatedId,
        public string $formVersion,
        public bool $formVersionKnown,
        public CarbonImmutable $submittedAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $endedAt,
        public string $rawStart,
        public string $rawEnd,
        public ?string $lgaRef,
        public ?string $wardRef,
        public ?string $facilityRef,
        public ?int $roundYear,
        public ?int $roundQuarter,
        public string $assessorName,
        public ?string $deviceId,
        public ?array $gps,
        public array $items,
        public ?array $rawNumericScores,
    ) {}

    /**
     * The local calendar day of the visit: when it started, or when it was submitted if the
     * start did not parse (COLUMN_DRIFT reports that separately).
     */
    public function visitDate(): CarbonImmutable
    {
        return ($this->startedAt ?? $this->submittedAt)->setTimezone((string) config('app.timezone'))->startOfDay();
    }

    /** @param  array<string, mixed>  $data  as produced by toArray() */
    public static function fromArray(array $data): self
    {
        $read = new ArrayReader($data, 'parsed submission');

        return new self(
            instanceId: $read->string('instance_id'),
            deprecatedId: $read->nullableString('deprecated_id'),
            formVersion: $read->string('form_version'),
            formVersionKnown: $read->bool('form_version_known'),
            submittedAt: $read->date('submitted_at'),
            startedAt: $read->nullableDate('started_at'),
            endedAt: $read->nullableDate('ended_at'),
            rawStart: $read->string('raw_start'),
            rawEnd: $read->string('raw_end'),
            lgaRef: $read->nullableString('lga_ref'),
            wardRef: $read->nullableString('ward_ref'),
            facilityRef: $read->nullableString('facility_ref'),
            roundYear: $read->nullableInt('round_year'),
            roundQuarter: $read->nullableInt('round_quarter'),
            assessorName: $read->string('assessor_name'),
            deviceId: $read->nullableString('device_id'),
            gps: $read->nullableFloatMap('gps'),
            items: array_map(ItemResponseData::fromArray(...), $read->listOfArrays('items')),
            rawNumericScores: $read->nullableFloatMap('raw_numeric_scores'),
        );
    }

    /**
     * JSON-safe; timestamps are ISO 8601 with their offset.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'deprecated_id' => $this->deprecatedId,
            'form_version' => $this->formVersion,
            'form_version_known' => $this->formVersionKnown,
            'submitted_at' => $this->submittedAt->toIso8601String(),
            'started_at' => $this->startedAt?->toIso8601String(),
            'ended_at' => $this->endedAt?->toIso8601String(),
            'raw_start' => $this->rawStart,
            'raw_end' => $this->rawEnd,
            'lga_ref' => $this->lgaRef,
            'ward_ref' => $this->wardRef,
            'facility_ref' => $this->facilityRef,
            'round_year' => $this->roundYear,
            'round_quarter' => $this->roundQuarter,
            'assessor_name' => $this->assessorName,
            'device_id' => $this->deviceId,
            'gps' => $this->gps,
            'items' => array_map(fn (ItemResponseData $item): array => $item->toArray(), $this->items),
            'raw_numeric_scores' => $this->rawNumericScores,
        ];
    }
}
