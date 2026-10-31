<?php

declare(strict_types=1);

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
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

// Phase 2B: an answer the form does not allow (the parser's 'invalid') is a missing answer. One
// among valid answers still fails: otherwise it would silently score as a fail.
it('treats an answer the form does not allow as missing, even among valid answers', function (): void {
    $submission = ParsedSubmissionBuilder::new()->withItemValue(Dimension::Availability, 1, 'tally_sheet', 'invalid')->build();

    $failures = checkRule(ItemsIncompleteRule::class, $submission);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->detail)->toBe('an answer the form does not allow: availability_m1 tally_sheet');
});

it('reports a slot answered only with invalid answers as missing too', function (): void {
    $builder = ParsedSubmissionBuilder::new();
    foreach (ParsedSubmissionBuilder::ITEM_CODES as $code) {
        $builder->withItemValue(Dimension::Validity, 2, $code, 'invalid');
    }

    $failures = checkRule(ItemsIncompleteRule::class, $builder->build());

    expect($failures[0]->detail)->toStartWith('missing validity_m2; an answer the form does not allow: validity_m2 register_present, validity_m2 summary_form');
});

// ---- A monthly visit (D-70): it covers the one slot of its month

/** A full submission cut down to the slots of a visit that covers only $slots. */
function onlySlots(array $slots, ?ParsedSubmission $submission = null): ParsedSubmission
{
    $submission ??= ParsedSubmissionBuilder::new()->build();

    return new ParsedSubmission(...[...get_object_vars($submission),
        'items' => array_values(array_filter($submission->items, fn (ItemResponseData $i): bool => in_array($i->monthSlot, $slots, true))),
        'monthSlots' => $slots,
    ]);
}

it('passes a monthly visit whose one slot has items in every dimension', function (): void {
    expect(checkRule(ItemsIncompleteRule::class, onlySlots([2])))->toBe([]);
});

it('requires a monthly visit\'s own slot in every dimension, and nothing else', function (): void {
    $submission = onlySlots([2]);
    $submission = new ParsedSubmission(...[...get_object_vars($submission),
        'items' => array_values(array_filter($submission->items, fn (ItemResponseData $i): bool => $i->dimension !== Dimension::Validity)),
    ]);

    expect(checkRule(ItemsIncompleteRule::class, $submission)[0]->detail)->toBe('missing validity_m2');
});

it('still fails a monthly visit whose one slot is all N/A in a dimension: nothing to score', function (): void {
    $submission = onlySlots([1]);
    $submission = new ParsedSubmission(...[...get_object_vars($submission),
        'items' => array_map(fn (ItemResponseData $i): ItemResponseData => $i->dimension === Dimension::Consistency
            ? new ItemResponseData($i->dimension, $i->monthSlot, $i->itemCode, 'na', false) : $i, $submission->items),
    ]);

    expect(checkRule(ItemsIncompleteRule::class, $submission)[0]->detail)->toContain('consistency');
});

it('still requires all nine slots of a visit that does not say otherwise (every ODK form)', function (): void {
    $submission = onlySlots([2]);
    $submission = new ParsedSubmission(...[...get_object_vars($submission), 'monthSlots' => null]);

    expect(checkRule(ItemsIncompleteRule::class, $submission)[0]->detail)->toContain('availability_m1')->toContain('validity_m3');
});
