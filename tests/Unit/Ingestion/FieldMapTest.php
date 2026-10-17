<?php

declare(strict_types=1);

use App\Domain\Ingestion\Odk\FieldMap;
use App\Domain\Scoring\Enums\Dimension;

// ARCHITECTURE.md §5.5: an additive version over the default, never a fallback for an unknown one.

const MAPS = [
    'default' => '2026.1',
    'versions' => [
        '2026.1' => [
            'facility_code' => 'facility.facility_code',
            'lga_code' => 'facility.lga',
            'ward_code' => 'facility.ward',
            'round_year' => 'round_year',
            'round_quarter' => 'round_quarter',
            'started_at' => 'start',
            'ended_at' => 'end',
            'assessor' => 'assessor_name',
            'gps' => 'gps',
            'scored_prefix_pattern' => '/^(avail|consist|valid)_m([1-3])_([a-z0-9_]+)$/',
            'renames' => [],
        ],
        '2025.2' => [
            'facility_code' => 'fac.fac_code',
            'renames' => ['avail_m1_nhmis_form' => 'avail_m1_nhmis_summary'],
        ],
    ],
];

it('gives the default its own full map', function (): void {
    $map = FieldMap::fromConfig(MAPS, '2026.1');

    expect($map)->not->toBeNull()
        ->and($map?->path('facility_code'))->toBe('facility.facility_code')
        ->and($map?->path('assessor'))->toBe('assessor_name');
});

it('merges an additive version over the default: its differences, the rest unchanged', function (): void {
    $map = FieldMap::fromConfig(MAPS, '2025.2');

    expect($map?->version)->toBe('2025.2')
        ->and($map?->path('facility_code'))->toBe('fac.fac_code')
        ->and($map?->path('lga_code'))->toBe('facility.lga')
        ->and($map?->canonicalName('avail_m1_nhmis_form'))->toBe('avail_m1_nhmis_summary')
        ->and($map?->canonicalName('avail_m2_register_present'))->toBe('avail_m2_register_present');
});

it('never falls back to the default for a version it does not know', function (?string $version): void {
    expect(FieldMap::fromConfig(MAPS, $version))->toBeNull();
})->with(['unknown' => ['2021.3'], 'blank' => [''], 'none' => [null], 'near miss' => ['2026.10'], 'padded' => [' 2026.1']]);

it('reads nested groups by dot path, and gives null for anything missing or not a group', function (): void {
    $map = FieldMap::fromConfig(MAPS, '2026.1');
    $payload = ['facility' => ['lga' => 'CHK', 'ward' => null], 'start' => '2026-04-14', 'oops' => 'flat'];

    expect($map?->value($payload, 'lga_code'))->toBe('CHK')
        ->and($map?->value($payload, 'ward_code'))->toBeNull()
        ->and($map?->value($payload, 'facility_code'))->toBeNull()
        ->and($map?->value(['facility' => 'not a group'], 'lga_code'))->toBeNull()
        ->and($map?->value($payload, 'started_at'))->toBe('2026-04-14')
        ->and(FieldMap::get(['a' => ['b' => ['c' => 7]]], 'a.b.c'))->toBe(7);
});

it('names a scored question by the regex, after renames', function (): void {
    $map = FieldMap::fromConfig(MAPS, '2025.2');

    expect($map?->scored('avail_m1_nhmis_form'))->toBe([Dimension::Availability, 1, 'nhmis_summary', 'avail_m1_nhmis_summary'])
        ->and($map?->scored('consist_m3_tally_sheet'))->toBe([Dimension::Consistency, 3, 'tally_sheet', 'consist_m3_tally_sheet'])
        ->and($map?->scored('valid_m2_reporting_rate'))->toBe([Dimension::Validity, 2, 'reporting_rate', 'valid_m2_reporting_rate'])
        ->and($map?->scored('avail_m4_x'))->toBeNull()
        ->and($map?->scored('assessor_name'))->toBeNull()
        ->and($map?->scored('Avail_m1_x'))->toBeNull();
});
