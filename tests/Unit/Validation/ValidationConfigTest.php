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

it('has a default form version with a full field map', function (): void {
    $default = config('edqa_field_maps.default');

    // Versions contain dots, so never config("edqa_field_maps.versions.{$v}"): index the array.
    expect($default)->toBe('2026.1')
        ->and(config('edqa_field_maps.versions')[$default] ?? null)->toHaveKeys([
            'facility_code', 'lga_code', 'ward_code', 'round_year', 'round_quarter', 'started_at',
            'ended_at', 'assessor', 'scored_prefix_pattern', 'choices',
        ]);
});

it('names, explains and words a detail for every rule', function (string $code): void {
    foreach (['name', 'why', 'detail'] as $part) {
        $text = __("validation_rules.{$code}.{$part}");
        expect($text)->toBeString()->not->toBe("validation_rules.{$code}.{$part}")->not->toBeEmpty();
    }
})->with(RULE_CODES);

it('fills the detail placeholders the way ARCHITECTURE.md §6 shows', function (): void {
    expect(__('validation_rules.SCORE_RANGE.detail', ['slot' => 'availability_m1', 'score' => '347.66']))
        ->toBe('availability_m1 value 347.66 exceeds 100')
        ->and(__('validation_rules.LGA_UNKNOWN.detail'))->toBe('lga was blank')
        ->and(__('validation_rules.LGA_UNKNOWN.detail_unknown', ['value' => 'Kaduna Central']))->toBe("lga 'Kaduna Central' is not one of the 23");
});
