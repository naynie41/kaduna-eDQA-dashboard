<?php

declare(strict_types=1);

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Scoring\Enums\Dimension;
use Carbon\CarbonImmutable;

function parsedSubmission(array $overrides = []): ParsedSubmission
{
    return new ParsedSubmission(...[
        'instanceId' => 'uuid:7c1f0e4a-1111-4a2b-9c3d-000000000001',
        'deprecatedId' => 'uuid:7c1f0e4a-1111-4a2b-9c3d-000000000000',
        'formVersion' => '2026.1',
        'formVersionKnown' => true,
        'submittedAt' => CarbonImmutable::parse('2026-04-14T09:50:00+01:00'),
        'startedAt' => CarbonImmutable::parse('2026-04-14T09:00:00+01:00'),
        'endedAt' => CarbonImmutable::parse('2026-04-14T09:45:00+01:00'),
        'rawStart' => '2026-04-14T09:00:00.000+01:00',
        'rawEnd' => '2026-04-14T09:45:00.000+01:00',
        'lgaRef' => 'CHK',
        'wardRef' => 'CHK-KAKAU',
        'facilityRef' => 'KD/CHK/0042',
        'roundYear' => 2026,
        'roundQuarter' => 2,
        'assessorName' => 'Hauwa Ibrahim',
        'deviceId' => 'collect:abc123',
        'gps' => ['lat' => 10.4, 'lng' => 7.4, 'accuracy' => 5.0],
        'items' => [
            new ItemResponseData(Dimension::Availability, 1, 'summary_form', 'pass', true),
            new ItemResponseData(Dimension::Validity, 3, 'tally_sheet', 'na', false),
        ],
        'rawNumericScores' => ['availability_m1' => 347.66],
        ...$overrides,
    ]);
}

it('survives a JSON round trip unchanged', function (array $overrides): void {
    $submission = parsedSubmission($overrides);

    $json = json_encode($submission->toArray(), JSON_THROW_ON_ERROR);
    $restored = ParsedSubmission::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));

    expect($restored)->toEqual($submission)
        ->and($restored->toArray())->toBe($submission->toArray());
})->with([
    'every field set' => [[]],
    'optional fields empty' => [[
        'deprecatedId' => null, 'startedAt' => null, 'endedAt' => null, 'lgaRef' => null,
        'wardRef' => null, 'facilityRef' => null, 'roundYear' => null, 'roundQuarter' => null,
        'deviceId' => null, 'gps' => null, 'items' => [], 'rawNumericScores' => null,
    ]],
]);

it('keeps the time zone offset of each timestamp', function (): void {
    $array = parsedSubmission()->toArray();

    expect($array['started_at'])->toBe('2026-04-14T09:00:00+01:00')
        ->and(ParsedSubmission::fromArray($array)->startedAt?->utcOffset())->toBe(60);
});

it('rejects an item outside the 3 month slots or with an unmapped value', function (int $slot, string $value): void {
    expect(fn () => new ItemResponseData(Dimension::Availability, $slot, 'summary_form', $value, true))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'slot 0' => [0, 'pass'],
    'slot 4' => [4, 'pass'],
    'raw choice "yes"' => [1, 'yes'],
]);

it('rejects a fixture missing a required field', function (): void {
    $array = parsedSubmission()->toArray();
    unset($array['instance_id']);

    expect(fn () => ParsedSubmission::fromArray($array))->toThrow(InvalidArgumentException::class, 'instance_id');
});

it('takes the visit date from the start time, in the app time zone', function (): void {
    $submission = parsedSubmission(['startedAt' => CarbonImmutable::parse('2026-04-14T23:30:00+00:00')]);

    expect($submission->visitDate()?->toDateString())->toBe('2026-04-15')
        ->and(parsedSubmission(['startedAt' => null])->visitDate())->toBeNull();
});
