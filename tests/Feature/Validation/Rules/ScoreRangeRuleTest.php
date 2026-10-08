<?php

declare(strict_types=1);

use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\ScoreRangeRule;
use Tests\Support\ParsedSubmissionBuilder;

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
});

it('is a hard rule that needs the parse', function (): void {
    $rule = app(ScoreRangeRule::class);

    expect($rule->code())->toBe('SCORE_RANGE')
        ->and($rule->severity())->toBe(Severity::Hard)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes scores derived from items, which always land within 0 to 100', function (string $all): void {
    expect(checkRule(ScoreRangeRule::class, ParsedSubmissionBuilder::new()->withAllItems($all)->build()))->toBe([]);
})->with(['pass', 'fail']);

it("fails a typed score above 100, like the live report's 347.66", function (): void {
    $failures = checkRule(ScoreRangeRule::class, ParsedSubmissionBuilder::new()->withRawNumericScore('availability_m1', 347.66)->build());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->code)->toBe('SCORE_RANGE')
        ->and($failures[0]->severity)->toBe(Severity::Hard)
        ->and($failures[0]->detail)->toBe('availability_m1 value 347.66 exceeds 100')
        ->and($failures[0]->field)->toBe('availability_m1');
});

it('fails a negative typed score and reports every bad value', function (): void {
    $submission = ParsedSubmissionBuilder::new()
        ->withRawNumericScore('availability_m1', 347.66)
        ->withRawNumericScore('validity_m3', -4)
        ->withRawNumericScore('consistency_m2', 392.42)
        ->build();

    expect(array_map(fn ($f) => $f->detail, checkRule(ScoreRangeRule::class, $submission)))->toBe([
        'availability_m1 value 347.66 exceeds 100',
        'validity_m3 value -4 is below 0',
        'consistency_m2 value 392.42 exceeds 100',
    ]);
});

it('accepts typed scores at exactly 0 and 100', function (): void {
    $submission = ParsedSubmissionBuilder::new()->withRawNumericScore('availability_m1', 0)->withRawNumericScore('validity_m3', 100)->build();

    expect(checkRule(ScoreRangeRule::class, $submission))->toBe([]);
});

it('skips the derived check, without an exception, when items are incomplete', function (): void {
    $builder = ParsedSubmissionBuilder::new()->withRawNumericScore('availability_m1', 347.66);
    foreach ([1, 2, 3] as $month) {
        $builder->withoutSlot(Dimension::Consistency, $month);
    }

    $failures = checkRule(ScoreRangeRule::class, $builder->build());

    // ITEMS_INCOMPLETE reports the gap; the typed score is still checked.
    expect(array_map(fn ($f) => $f->detail, $failures))->toBe(['availability_m1 value 347.66 exceeds 100']);
});

it('still checks typed scores when no rule version is published', function (): void {
    ScoringRuleVersion::query()->update(['published_at' => null, 'published_reason' => null]);

    expect(checkRule(ScoreRangeRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([])
        ->and(checkRule(ScoreRangeRule::class, ParsedSubmissionBuilder::new()->withRawNumericScore('overall', 101)->build()))->toHaveCount(1);
});
