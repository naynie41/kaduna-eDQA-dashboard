<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Builds a ParsedSubmission that passes every validation rule by default: an active facility,
 * an open round whose window holds the visit, all 3 dimensions × 3 month slots with items scoring
 * 75 (not all perfect), a 45-minute visit and the default form version.
 *
 * The facility and round are created on build() unless given. Each with*() / without*() breaks
 * exactly one thing; nothing else is adjusted to match. withRawStart('FEBRUARY'), for example,
 * changes only the raw string, not startedAt: pair it with withStartedAt(null) to mimic the parser.
 */
final class ParsedSubmissionBuilder
{
    /** Item codes per slot; the default answers are pass, pass, pass, fail (75). */
    public const ITEM_CODES = ['register_present', 'summary_form', 'tally_sheet', 'reporting_rate'];

    public const VISIT_MINUTES = 45;

    private ?Facility $facility = null;

    private ?Round $round = null;

    /** @var array<string, mixed> Overrides of ParsedSubmission fields, applied last. */
    private array $overrides = [];

    /** @var array<string, true> "dimension:slot" pairs to leave out. */
    private array $missingSlots = [];

    /** @var array<string, string> "dimension:slot:item" => value. */
    private array $itemValues = [];

    private ?int $visitMinutes = null;

    public static function new(): self
    {
        return new self;
    }

    public function forFacility(Facility $facility): self
    {
        $this->facility = $facility;

        return $this;
    }

    public function inRound(Round $round): self
    {
        $this->round = $round;

        return $this;
    }

    public function withInstanceId(string $instanceId): self
    {
        return $this->set('instanceId', $instanceId);
    }

    public function withDeprecatedId(?string $deprecatedId): self
    {
        return $this->set('deprecatedId', $deprecatedId);
    }

    /**
     * By default formVersionKnown follows the config, as the parser would set it; pass $known
     * to make the parser's answer disagree with the config.
     */
    public function withFormVersion(string $version, ?bool $known = null): self
    {
        $this->set('formVersion', $version);

        return $this->set('formVersionKnown', $known ?? array_key_exists($version, self::knownVersions()));
    }

    public function withLga(?string $lgaRef): self
    {
        return $this->set('lgaRef', $lgaRef);
    }

    public function withWard(?string $wardRef): self
    {
        return $this->set('wardRef', $wardRef);
    }

    public function withFacilityRef(?string $facilityRef): self
    {
        return $this->set('facilityRef', $facilityRef);
    }

    public function withRoundRef(?int $year, ?int $quarter): self
    {
        $this->set('roundYear', $year);

        return $this->set('roundQuarter', $quarter);
    }

    public function withRawStart(string $rawStart): self
    {
        return $this->set('rawStart', $rawStart);
    }

    public function withRawEnd(string $rawEnd): self
    {
        return $this->set('rawEnd', $rawEnd);
    }

    public function withStartedAt(?CarbonImmutable $startedAt): self
    {
        return $this->set('startedAt', $startedAt);
    }

    public function withEndedAt(?CarbonImmutable $endedAt): self
    {
        return $this->set('endedAt', $endedAt);
    }

    /** Visit length; moves endedAt (and rawEnd) only. */
    public function withVisitMinutes(int $minutes): self
    {
        $this->visitMinutes = $minutes;

        return $this;
    }

    public function withAssessor(string $assessorName): self
    {
        return $this->set('assessorName', $assessorName);
    }

    public function withDeviceId(?string $deviceId): self
    {
        return $this->set('deviceId', $deviceId);
    }

    /** @param  array<string, float>|null  $gps */
    public function withGps(?array $gps): self
    {
        return $this->set('gps', $gps);
    }

    public function withoutSlot(Dimension $dimension, int $monthSlot): self
    {
        $this->missingSlots["{$dimension->value}:{$monthSlot}"] = true;

        return $this;
    }

    /** @param  'pass'|'fail'|'na'  $value */
    public function withItemValue(Dimension $dimension, int $monthSlot, string $itemCode, string $value): self
    {
        $this->itemValues["{$dimension->value}:{$monthSlot}:{$itemCode}"] = $value;

        return $this;
    }

    /** @param  'pass'|'fail'|'na'  $value */
    public function withAllItems(string $value): self
    {
        foreach (Dimension::cases() as $dimension) {
            foreach ([1, 2, 3] as $slot) {
                foreach (self::ITEM_CODES as $code) {
                    $this->withItemValue($dimension, $slot, $code, $value);
                }
            }
        }

        return $this;
    }

    /** Legacy numeric-entry forms only, keyed "availability_m1" etc. */
    public function withRawNumericScore(string $key, float $score): self
    {
        $scores = $this->overrides['rawNumericScores'] ?? [];
        $scores[$key] = $score;

        return $this->set('rawNumericScores', $scores);
    }

    public function build(): ParsedSubmission
    {
        $facility = $this->facility ??= Facility::factory()->create();
        $round = $this->round ??= Round::factory()->create();
        $facility->loadMissing(['lga', 'ward']);

        // 09:00 on the 14th day of the window, local time.
        $startedAt = CarbonImmutable::parse($round->window_start->toDateString().' 09:00', (string) config('app.timezone'))->addDays(13);
        $endedAt = $startedAt->addMinutes($this->visitMinutes ?? self::VISIT_MINUTES);

        $fields = [
            'instanceId' => 'uuid:'.Str::uuid()->toString(),
            'deprecatedId' => null,
            'formVersion' => (string) config('edqa_field_maps.default'),
            'formVersionKnown' => true,
            'submittedAt' => $endedAt->addMinutes(5),
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
            'rawStart' => $startedAt->format('Y-m-d\TH:i:s.vP'),
            'rawEnd' => $endedAt->format('Y-m-d\TH:i:s.vP'),
            'lgaRef' => $facility->lga->code,
            'wardRef' => $facility->ward->code,
            'facilityRef' => $facility->code,
            'roundYear' => $round->year,
            'roundQuarter' => $round->quarter,
            'assessorName' => 'Hauwa Ibrahim',
            'deviceId' => 'collect:'.Str::lower(Str::random(16)),
            'gps' => ['lat' => (float) $facility->lat, 'lng' => (float) $facility->lng, 'accuracy' => 5.0],
            'items' => $this->items(),
            'rawNumericScores' => null,
            ...$this->overrides,
        ];

        return new ParsedSubmission(...$fields);
    }

    /** @return list<ItemResponseData> */
    private function items(): array
    {
        $items = [];
        foreach (Dimension::cases() as $dimension) {
            foreach ([1, 2, 3] as $slot) {
                if (isset($this->missingSlots["{$dimension->value}:{$slot}"])) {
                    continue;
                }
                foreach (self::ITEM_CODES as $i => $code) {
                    $value = $this->itemValues["{$dimension->value}:{$slot}:{$code}"] ?? ($i === 3 ? 'fail' : 'pass');
                    $items[] = new ItemResponseData($dimension, $slot, $code, $value, $value !== 'na');
                }
            }
        }

        return $items;
    }

    private function set(string $field, mixed $value): self
    {
        $this->overrides[$field] = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    private static function knownVersions(): array
    {
        $versions = config('edqa_field_maps.versions');

        return is_array($versions) ? $versions : [];
    }
}
