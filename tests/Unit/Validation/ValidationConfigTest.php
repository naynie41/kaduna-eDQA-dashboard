<?php

declare(strict_types=1);

// ARCHITECTURE.md §6: the 14 rules, in catalogue order (UNKNOWN_FORM_VERSION first).
const RULE_CODES = [
    'UNKNOWN_FORM_VERSION', 'COLUMN_DRIFT', 'LGA_UNKNOWN', 'FACILITY_UNKNOWN',
    'FACILITY_LGA_MISMATCH', 'ITEMS_INCOMPLETE', 'SCORE_RANGE', 'END_BEFORE_START',
    'ROUND_WINDOW', 'DUPLICATE_ASSESSMENT', 'SCORE_JUMP', 'ALL_PERFECT', 'VISIT_TOO_SHORT',
    'ASSESSOR_VOLUME',
];

it('registers the 14 rules in catalogue order, one class each', function (): void {
    $rules = config('edqa.validation.rules');

    expect(array_keys($rules))->toBe(RULE_CODES)
        ->and(array_unique(array_values($rules)))->toHaveCount(14);

    foreach ($rules as $class) {
        expect($class)->toStartWith('App\\Domain\\Validation\\Rules\\');
    }
});

it('holds the documented soft-rule thresholds', function (): void {
    expect(config('edqa.validation.soft_rules'))->toBe([
        'score_jump_points' => 30,
        'visit_min_minutes' => 20,
        'assessor_max_per_day' => 6,
    ]);
});

it('reserves the words that leak into the ward column', function (): void {
    $words = config('edqa.validation.reserved_ward_words');
    $months = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august',
        'september', 'october', 'november', 'december'];

    expect($words)->toEqualCanonicalizing(['quarterly', 'monthly', 'annual', 'null', 'n/a', 'none', ...$months]);
});

it('maps the current ODK form version to a full field map', function (): void {
    $current = config('edqa_field_maps.current.odk');
    // Versions contain dots, so never config("edqa_field_maps.sources.odk.{$v}"): index the array.
    $name = config('edqa_field_maps.sources')['odk'][$current] ?? null;

    expect($current)->toBe('2026.1')
        ->and($name)->toBe('xls-2026.1')
        ->and(config('edqa_field_maps.maps')[$name] ?? null)->toHaveKeys([
            'facility_code', 'lga_code', 'ward_code', 'round_year', 'round_quarter', 'started_at',
            'ended_at', 'assessor', 'gps', 'scored_prefix_pattern', 'renames',
        ])
        // Answers map through the rule version's choice_map (Q-25), never the field map.
        ->and(config('edqa_field_maps.maps')[$name])->not->toHaveKey('choices')
        ->and(config('edqa_field_maps.maps')[$name])->not->toHaveKey('extends')
        // The same XLSForms on Kobo, under fictional deployment uids (CONTRACT-ASSUMED), each
        // pointing at a map that exists.
        ->and(config('edqa_field_maps.sources')['kobo'])->toHaveCount(2)
        ->and(array_diff(array_values(config('edqa_field_maps.sources')['kobo']), array_keys(config('edqa_field_maps.maps'))))->toBe([]);
});

it('names, explains and words a detail for every rule', function (string $code): void {
    foreach (['name', 'why', 'detail'] as $part) {
        $text = __("validation_rules.{$code}.{$part}");
        expect($text)->toBeString()->not->toBe("validation_rules.{$code}.{$part}")->not->toBeEmpty();
    }
})->with(RULE_CODES);

it('never writes a soft-rule threshold into its text', function (string $code, string $placeholder): void {
    expect(__("validation_rules.{$code}.why"))->toContain($placeholder)
        ->not->toMatch('/\b(30|20|6|six)\b/');
})->with([
    ['SCORE_JUMP', ':points'],
    ['VISIT_TOO_SHORT', ':minutes'],
    ['ASSESSOR_VOLUME', ':maximum'],
]);

it('fills the detail placeholders the way ARCHITECTURE.md §6 shows', function (): void {
    expect(__('validation_rules.SCORE_RANGE.detail', ['slot' => 'availability_m1', 'score' => '347.66']))
        ->toBe('availability_m1 value 347.66 exceeds 100')
        ->and(__('validation_rules.LGA_UNKNOWN.detail'))->toBe('lga was blank')
        ->and(__('validation_rules.LGA_UNKNOWN.detail_unknown', ['value' => 'Kaduna Central']))->toBe("lga 'Kaduna Central' is not one of the 23");
});
