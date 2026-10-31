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
 * COLUMN_DRIFT can name the bad value. rawNumericScores holds a number found in a scored question
 * (a legacy typed score, Q-16), keyed by the question's name in the default form ("avail_m1_score");
 * it is checked (SCORE_RANGE) but never used as a score.
 *
 * monthSlots: the month slots the visit covers. Null is all three (a quarterly visit, and every
 * ODK form); a monthly visit (D-70) covers the one slot of its month, and only that slot must have
 * items (ITEMS_INCOMPLETE). outOfScope: why the submission is not a facility assessment at all
 * (D-74: 'community_visit', 'ddu'), or null; such a submission is stored but never validated or
 * scored.
 */
final readonly class ParsedSubmission
{
    /**
     * @param  array<string, float>|null  $gps  lat, lng and accuracy
     * @param  list<ItemResponseData>  $items
     * @param  array<string, float>|null  $rawNumericScores
     * @param  list<int>|null  $monthSlots
     */
    public function __construct(
        public string $source,
        public ?string $externalId,
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
        public ?array $monthSlots = null,
        public ?string $outOfScope = null,
    ) {}

    /**
     * The month slots the visit must cover: its own, or all three.
     *
     * @return list<int>
     */
    public function coveredSlots(): array
    {
        return $this->monthSlots ?? [1, 2, 3];
    }

    /** The same submission as the version that replaces $deprecatedId (an edit found by external ID, D-61). */
    public function asEditOf(string $deprecatedId): self
    {
        return new self(...[...get_object_vars($this), 'deprecatedId' => $deprecatedId]);
    }

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
            source: $read->string('source'),
            externalId: $read->nullableString('external_id'),
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
            // Absent from snapshots stored before monthly visits and exclusions existed: all slots, in scope.
            monthSlots: is_array($data['month_slots'] ?? null) ? array_values(array_map(intval(...), $data['month_slots'])) : null,
            outOfScope: is_string($data['out_of_scope'] ?? null) ? $data['out_of_scope'] : null,
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
            'source' => $this->source,
            'external_id' => $this->externalId,
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
            'month_slots' => $this->monthSlots,
            'out_of_scope' => $this->outOfScope,
        ];
    }
}
