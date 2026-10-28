<?php

declare(strict_types=1);

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\SubmissionParser;
use App\Domain\Scoring\CurrentRuleVersion;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Support\Facades\DB;

// SubmissionParser (ARCHITECTURE.md §5.5): one ODK Central OData entry → ParsedSubmission. It
// never throws on bad data: what it cannot read is passed through raw for validation to quarantine.

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    CurrentRuleVersion::forget();
});

/** The valid raw fixture, as a starting point. */
function rawVisit(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/odk/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
}

function parse(array $payload): ParsedSubmission
{
    return odkParse($payload);
}

it('maps answers through the published rule version\'s choice map', function (): void {
    $config = json_decode((string) DB::table('scoring_rule_versions')->value('config'), true);
    $config['choice_map'] = ['yes' => 'pass', 'no' => 'fail', 'na' => 'na', 'not_seen' => 'fail'];
    DB::table('scoring_rule_versions')->update(['config' => json_encode($config)]);
    CurrentRuleVersion::forget();
    $raw = rawVisit();
    $raw['availability']['avail_m1_tally_sheet'] = 'not_seen';

    $item = collect(parse($raw)->items)->first(fn (ItemResponseData $i): bool => $i->itemCode === 'tally_sheet');

    expect($item?->value)->toBe('fail')->and($item?->isApplicable)->toBeTrue();
});

it('keeps an answer the choice map does not know as invalid, never guessing', function (mixed $answer): void {
    $raw = rawVisit();
    $raw['availability']['avail_m1_tally_sheet'] = $answer;

    $item = collect(parse($raw)->items)->first(fn (ItemResponseData $i): bool => $i->itemCode === 'tally_sheet' && $i->monthSlot === 1);

    expect($item?->value)->toBe('invalid')->and($item?->isApplicable)->toBeFalse();
})->with(['unknown word' => ['maybe'], 'capitals' => ['YES'], 'true' => [true], 'a list' => [['yes']]]);

it('puts a number in a scored question into rawNumericScores, by question name, not into items', function (mixed $number): void {
    $raw = rawVisit();
    $raw['availability']['avail_m1_score'] = $number;

    $parsed = parse($raw);

    expect($parsed->rawNumericScores)->toBe(['avail_m1_score' => 347.66])
        ->and(collect($parsed->items)->contains(fn (ItemResponseData $i): bool => $i->itemCode === 'score'))->toBeFalse();
})->with(['a number' => [347.66], 'a numeric string' => ['347.66']]);

it('skips an unanswered question (null), so a missing slot stays missing', function (): void {
    $raw = rawVisit();
    foreach (['nhmis_summary', 'register_present', 'tally_sheet', 'reporting_rate'] as $code) {
        $raw['validity']["valid_m3_{$code}"] = null;
    }

    expect(collect(parse($raw)->items)->contains(fn (ItemResponseData $i): bool => $i->dimension->value === 'validity' && $i->monthSlot === 3))->toBeFalse();
});

it('reads only strict ISO 8601 times, keeping the raw text either way', function (mixed $start, ?string $parsed, string $raw): void {
    $payload = rawVisit();
    $payload['start'] = $start;

    $submission = parse($payload);

    expect($submission->startedAt?->toIso8601String())->toBe($parsed)
        ->and($submission->rawStart)->toBe($raw);
})->with([
    'ISO with offset' => ['2026-04-14T09:00:00.000+01:00', '2026-04-14T09:00:00+01:00', '2026-04-14T09:00:00.000+01:00'],
    'a month name' => ['FEBRUARY', null, 'FEBRUARY'],
    'a date only' => ['2026-04-14', null, '2026-04-14'],
    'nonsense' => ['2026-13-45T99:00:00Z', null, '2026-13-45T99:00:00Z'],
    'a number' => [20260414, null, '20260414'],
    'missing' => [null, null, ''],
]);

it('reads ODK\'s GeoJSON point (lon, lat, alt) as lat and lng, with an accuracy only if one is given', function (): void {
    expect(parse(rawVisit())->gps)->toBe(['lat' => 10.4806, 'lng' => 7.4302]); // Central's OData omits accuracy

    $withAccuracy = rawVisit();
    $withAccuracy['gps']['properties'] = ['accuracy' => 4.8];
    expect(parse($withAccuracy)->gps)->toBe(['lat' => 10.4806, 'lng' => 7.4302, 'accuracy' => 4.8]);

    $raw = rawVisit();
    $raw['gps'] = ['type' => 'Point', 'coordinates' => 'nowhere'];
    expect(parse($raw)->gps)->toBeNull();
});

it('passes awkward references through as text for validation to judge', function (): void {
    $raw = rawVisit();
    $raw['facility'] = ['lga' => null, 'ward' => 'Quarterly', 'facility_code' => 2019.5];
    $raw['round_year'] = '2026';
    $raw['round_quarter'] = 'two';

    $parsed = parse($raw);

    expect([$parsed->lgaRef, $parsed->wardRef, $parsed->facilityRef, $parsed->roundYear, $parsed->roundQuarter])
        ->toBe([null, 'Quarterly', '2019.5', 2026, null]);
});

it('reads an unknown form version as minimal fields, flagged unknown', function (): void {
    $raw = rawVisit();
    $raw['__system']['formVersion'] = '2021.3';

    $parsed = parse($raw);

    expect($parsed->formVersionKnown)->toBeFalse()
        ->and($parsed->formVersion)->toBe('2021.3')
        ->and($parsed->instanceId)->toBe($raw['meta']['instanceID'])
        ->and([$parsed->facilityRef, $parsed->lgaRef, $parsed->startedAt, $parsed->items, $parsed->rawNumericScores])->toBe([null, null, null, [], null]);
});

it('takes the instance ID from __id when meta is missing', function (): void {
    $raw = rawVisit();
    unset($raw['meta']);

    expect(parse($raw)->instanceId)->toBe($raw['__id']);
});
