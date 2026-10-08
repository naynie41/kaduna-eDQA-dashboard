<?php

declare(strict_types=1);

use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\ItemsIncompleteRule;
use Tests\Support\ParsedSubmissionBuilder;

it('is a hard rule that needs the parse', function (): void {
    $rule = app(ItemsIncompleteRule::class);

    expect($rule->code())->toBe('ITEMS_INCOMPLETE')
        ->and($rule->severity())->toBe(Severity::Hard)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes items in all nine dimension-month slots', function (): void {
    expect(checkRule(ItemsIncompleteRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([]);
});

it('lists every missing slot', function (): void {
    $submission = ParsedSubmissionBuilder::new()
        ->withoutSlot(Dimension::Validity, 3)->withoutSlot(Dimension::Consistency, 2)->build();

    $failures = checkRule(ItemsIncompleteRule::class, $submission);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->severity)->toBe(Severity::Hard)
        ->and($failures[0]->detail)->toBe('missing consistency_m2, validity_m3')
        ->and($failures[0]->field)->toBe('items');
});

it('counts an all-N/A slot as present: the calculator excludes it', function (): void {
    $builder = ParsedSubmissionBuilder::new();
    foreach (ParsedSubmissionBuilder::ITEM_CODES as $code) {
        $builder->withItemValue(Dimension::Availability, 2, $code, 'na');
    }

    expect(checkRule(ItemsIncompleteRule::class, $builder->build()))->toBe([]);
});

it('fails a dimension that is all N/A in every month', function (): void {
    $builder = ParsedSubmissionBuilder::new();
    foreach ([1, 2, 3] as $month) {
        foreach (ParsedSubmissionBuilder::ITEM_CODES as $code) {
            $builder->withItemValue(Dimension::Consistency, $month, $code, 'na');
        }
    }

    expect(checkRule(ItemsIncompleteRule::class, $builder->build())[0]->detail ?? null)
        ->toBe('consistency has no applicable item in any month');
});

it('reports missing slots and an all-N/A dimension together', function (): void {
    $builder = ParsedSubmissionBuilder::new()->withoutSlot(Dimension::Availability, 1)->withoutSlot(Dimension::Availability, 2);
    foreach (ParsedSubmissionBuilder::ITEM_CODES as $code) {
        $builder->withItemValue(Dimension::Availability, 3, $code, 'na');
    }

    expect(checkRule(ItemsIncompleteRule::class, $builder->build())[0]->detail ?? null)
        ->toBe('missing availability_m1, availability_m2; availability has no applicable item in any month');
});

it('quarantines a legacy form with typed scores and no items (Q-16)', function (): void {
    $builder = ParsedSubmissionBuilder::new()->withRawNumericScore('availability_m1', 92.5);
    foreach (Dimension::cases() as $dimension) {
        foreach ([1, 2, 3] as $month) {
            $builder->withoutSlot($dimension, $month);
        }
    }

    $failures = checkRule(ItemsIncompleteRule::class, $builder->build());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->detail)->toBe('no item responses: this form recorded typed scores, which the portal never accepts as scores');
});
