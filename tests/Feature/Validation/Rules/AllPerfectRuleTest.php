<?php

declare(strict_types=1);

use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\AllPerfectRule;
use Tests\Support\ParsedSubmissionBuilder;

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
});

it('is a soft rule that needs the parse', function (): void {
    $rule = app(AllPerfectRule::class);

    expect($rule->code())->toBe('ALL_PERFECT')
        ->and($rule->severity())->toBe(Severity::Soft)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes an ordinary visit', function (): void {
    expect(checkRule(AllPerfectRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([]);
});

it('flags all nine slots at exactly 100', function (): void {
    $failures = checkRule(AllPerfectRule::class, ParsedSubmissionBuilder::new()->withAllItems('pass')->build());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->severity)->toBe(Severity::Soft)
        ->and($failures[0]->detail)->toBe('all 9 dimension-month scores are exactly 100')
        ->and($failures[0]->field)->toBe('items');
});

it('passes when a single item fails', function (): void {
    $submission = ParsedSubmissionBuilder::new()->withAllItems('pass')
        ->withItemValue(Dimension::Validity, 3, ParsedSubmissionBuilder::ITEM_CODES[0], 'fail')->build();

    expect(checkRule(AllPerfectRule::class, $submission))->toBe([]);
});

it('passes when one slot is all N/A, so only eight slots score 100', function (): void {
    $builder = ParsedSubmissionBuilder::new()->withAllItems('pass');
    foreach (ParsedSubmissionBuilder::ITEM_CODES as $code) {
        $builder->withItemValue(Dimension::Consistency, 2, $code, 'na');
    }

    expect(checkRule(AllPerfectRule::class, $builder->build()))->toBe([]);
});

it('still flags when N/A items sit beside passing ones in every slot', function (): void {
    $builder = ParsedSubmissionBuilder::new()->withAllItems('pass');
    foreach (Dimension::cases() as $dimension) {
        foreach ([1, 2, 3] as $month) {
            $builder->withItemValue($dimension, $month, ParsedSubmissionBuilder::ITEM_CODES[3], 'na');
        }
    }

    expect(checkRule(AllPerfectRule::class, $builder->build()))->toHaveCount(1);
});

it('stays silent when the submission cannot be scored', function (): void {
    $builder = ParsedSubmissionBuilder::new()->withAllItems('pass')->withoutSlot(Dimension::Availability, 1);

    expect(checkRule(AllPerfectRule::class, $builder->build()))->toBe([]);
});
