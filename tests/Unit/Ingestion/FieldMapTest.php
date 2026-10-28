<?php

declare(strict_types=1);

use App\Domain\Ingestion\FieldMap;
use App\Domain\Scoring\Enums\Dimension;

// ARCHITECTURE.md §5.5: named maps, an additive one over the map it extends, chosen per (source,
// version), and never a fallback for a pair with no entry.

const MAPS = [
    'maps' => [
        'xls-2026.1' => [
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
        'xls-2025.2' => [
            'extends' => 'xls-2026.1',
            'facility_code' => 'fac.fac_code',
            'renames' => ['avail_m1_nhmis_form' => 'avail_m1_nhmis_summary'],
        ],
        'loop-a' => ['extends' => 'loop-b'],
        'loop-b' => ['extends' => 'loop-a'],
        'orphan' => ['extends' => 'missing'],
    ],
    'sources' => [
        'odk' => ['2026.1' => 'xls-2026.1', '2025.2' => 'xls-2025.2', '9.9' => 'loop-a', '9.8' => 'orphan', '9.7' => 'not-a-map'],
        // The same XLSForm on Kobo, labelled by its deployment.
        'kobo' => ['vQ8xK2' => 'xls-2026.1'],
    ],
];

it('gives the base map its own full map', function (): void {
    $map = FieldMap::fromConfig(MAPS, 'odk', '2026.1');

    expect($map)->not->toBeNull()
        ->and($map?->name)->toBe('xls-2026.1')
        ->and($map?->path('facility_code'))->toBe('facility.facility_code')
        ->and($map?->path('assessor'))->toBe('assessor_name');
});

it('merges an additive map over the one it extends: its differences, the rest unchanged', function (): void {
    $map = FieldMap::fromConfig(MAPS, 'odk', '2025.2');

    expect($map?->version)->toBe('2025.2')
        ->and($map?->name)->toBe('xls-2025.2')
        ->and($map?->path('facility_code'))->toBe('fac.fac_code')
        ->and($map?->path('lga_code'))->toBe('facility.lga')
        ->and($map?->path('extends'))->toBeNull()
        ->and($map?->canonicalName('avail_m1_nhmis_form'))->toBe('avail_m1_nhmis_summary')
        ->and($map?->canonicalName('avail_m2_register_present'))->toBe('avail_m2_register_present');
});

it('never falls back to another map for a version it does not know', function (?string $version): void {
    expect(FieldMap::fromConfig(MAPS, 'odk', $version))->toBeNull();
})->with(['unknown' => ['2021.3'], 'blank' => [''], 'none' => [null], 'near miss' => ['2026.10'], 'padded' => [' 2026.1']]);

it('reads the same map for the same form on another source, under that source\'s own version label', function (): void {
    $kobo = FieldMap::fromConfig(MAPS, 'kobo', 'vQ8xK2');

    expect($kobo?->source)->toBe('kobo')
        ->and($kobo?->name)->toBe('xls-2026.1')
        ->and($kobo?->path('facility_code'))->toBe('facility.facility_code');
});

it('gives null for any unmapped pair: a version another source knows, an unknown source, a broken chain', function (string $source, string $version): void {
    expect(FieldMap::fromConfig(MAPS, $source, $version))->toBeNull();
})->with([
    'odk version on kobo' => ['kobo', '2026.1'],
    'kobo version on odk' => ['odk', 'vQ8xK2'],
    'unknown source' => ['aggregate', '2026.1'],
    'extends loop' => ['odk', '9.9'],
    'extends a missing map' => ['odk', '9.8'],
    'names a missing map' => ['odk', '9.7'],
]);

it('reads nested groups by dot path, and gives null for anything missing or not a group', function (): void {
    $map = FieldMap::fromConfig(MAPS, 'odk', '2026.1');
    $data = ['facility' => ['lga' => 'CHK', 'ward' => null], 'start' => '2026-04-14', 'oops' => 'flat'];

    expect($map?->value($data, 'lga_code'))->toBe('CHK')
        ->and($map?->value($data, 'ward_code'))->toBeNull()
        ->and($map?->value($data, 'facility_code'))->toBeNull()
        ->and($map?->value(['facility' => 'not a group'], 'lga_code'))->toBeNull()
        ->and($map?->value($data, 'started_at'))->toBe('2026-04-14')
        ->and(FieldMap::get(['a' => ['b' => ['c' => 7]]], 'a.b.c'))->toBe(7);
});

it('names a scored question by the regex, after renames', function (): void {
    $map = FieldMap::fromConfig(MAPS, 'odk', '2025.2');

    expect($map?->scored('avail_m1_nhmis_form'))->toBe([Dimension::Availability, 1, 'nhmis_summary', 'avail_m1_nhmis_summary'])
        ->and($map?->scored('consist_m3_tally_sheet'))->toBe([Dimension::Consistency, 3, 'tally_sheet', 'consist_m3_tally_sheet'])
        ->and($map?->scored('valid_m2_reporting_rate'))->toBe([Dimension::Validity, 2, 'reporting_rate', 'valid_m2_reporting_rate'])
        ->and($map?->scored('avail_m4_x'))->toBeNull()
        ->and($map?->scored('assessor_name'))->toBeNull()
        ->and($map?->scored('Avail_m1_x'))->toBeNull();
});
