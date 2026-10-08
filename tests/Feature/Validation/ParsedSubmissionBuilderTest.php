<?php

declare(strict_types=1);

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\ValidationContextFactory;
use Tests\Support\ParsedSubmissionBuilder;

// The builder's default must be a submission no rule can fault; tests then break one thing.

it('builds a submission that every lookup resolves', function (): void {
    $submission = ParsedSubmissionBuilder::new()->build();
    $context = app(ValidationContextFactory::class)->forBatch([$submission]);

    $facility = $context->activeFacility($submission->facilityRef);
    $round = $context->roundFor($submission);

    expect($context->isKnownFormVersion($submission->formVersion))->toBeTrue()
        ->and($submission->formVersionKnown)->toBeTrue()
        ->and($context->lga($submission->lgaRef)?->id)->toBe($facility?->lgaId)
        ->and($round?->isOpen())->toBeTrue()
        ->and($submission->roundYear)->toBe($round?->year)
        ->and($submission->roundQuarter)->toBe($round?->quarter)
        ->and($context->duplicateOf($submission, $round->id, $facility->id))->toBeNull()
        ->and($context->priorOverallScore($facility->id, $round))->toBeNull()
        ->and($context->assessorVisitCount($submission->assessorName, $submission->visitDate()))->toBe(0)
        ->and($submission->rawNumericScores)->toBeNull();
});

it('fills every dimension and month slot, scoring 75 and not all perfect', function (): void {
    $submission = ParsedSubmissionBuilder::new()->build();
    $slots = collect($submission->items)->groupBy(fn (ItemResponseData $i): string => "{$i->dimension->value}:{$i->monthSlot}");

    expect($slots)->toHaveCount(9)
        ->and($slots->every(fn ($items): bool => $items->count() === 4))->toBeTrue()
        ->and($slots->every(fn ($items): bool => $items->where('value', 'pass')->count() === 3))->toBeTrue();
});

it('makes a 45-minute visit whose raw dates match the parsed ones', function (): void {
    $submission = ParsedSubmissionBuilder::new()->build();

    expect($submission->startedAt->diffInMinutes($submission->endedAt))->toEqual(45)
        ->and($submission->rawStart)->toBe($submission->startedAt->format('Y-m-d\TH:i:s.vP'))
        ->and($submission->submittedAt->greaterThan($submission->endedAt))->toBeTrue();
});

it('raises no failure from any rule that exists yet', function (): void {
    $submission = ParsedSubmissionBuilder::new()->build();
    $context = app(ValidationContextFactory::class)->forBatch([$submission]);
    $existing = array_filter(config('edqa.validation.rules'), class_exists(...));

    $failures = [];
    foreach ($existing as $class) {
        $rule = app($class);
        expect($rule)->toBeInstanceOf(ValidationRule::class);
        $failures = [...$failures, ...$rule->check($submission, $context)];
    }

    // No rule classes exist in step 2A.1; this grows with them in 2B.
    expect($failures)->toBe([]);
});

it('breaks exactly one thing at a time', function (Closure $break, array $changed): void {
    $builder = ParsedSubmissionBuilder::new();
    $valid = $builder->build()->toArray();
    $broken = $break($builder)->build()->toArray();

    // instance_id and device_id are fresh on every build.
    $diff = array_keys(array_diff_assoc(array_map(serialize(...), $valid), array_map(serialize(...), $broken)));

    expect(array_values(array_diff($diff, ['instance_id', 'device_id'])))->toBe($changed);
})->with([
    'no LGA' => [fn (ParsedSubmissionBuilder $b) => $b->withLga(null), ['lga_ref']],
    'facility 2019.00' => [fn (ParsedSubmissionBuilder $b) => $b->withFacilityRef('2019.00'), ['facility_ref']],
    'ward Quarterly' => [fn (ParsedSubmissionBuilder $b) => $b->withWard('Quarterly'), ['ward_ref']],
    'start FEBRUARY' => [fn (ParsedSubmissionBuilder $b) => $b->withRawStart('FEBRUARY'), ['raw_start']],
    'no validity month 3' => [fn (ParsedSubmissionBuilder $b) => $b->withoutSlot(Dimension::Validity, 3), ['items']],
    'typed score 347.66' => [fn (ParsedSubmissionBuilder $b) => $b->withRawNumericScore('availability_m1', 347.66), ['raw_numeric_scores']],
    'unknown form version' => [fn (ParsedSubmissionBuilder $b) => $b->withFormVersion('2019.1'), ['form_version', 'form_version_known']],
    '12-minute visit' => [fn (ParsedSubmissionBuilder $b) => $b->withVisitMinutes(12), ['submitted_at', 'ended_at', 'raw_end']],
    'all perfect' => [fn (ParsedSubmissionBuilder $b) => $b->withAllItems('pass'), ['items']],
]);

it('stores the values it was told to break', function (): void {
    $submission = ParsedSubmissionBuilder::new()
        ->withLga(null)->withRawNumericScore('availability_m1', 347.66)->withoutSlot(Dimension::Validity, 3)->build();

    expect($submission->lgaRef)->toBeNull()
        ->and($submission->rawNumericScores)->toBe(['availability_m1' => 347.66])
        ->and(collect($submission->items)->contains(fn (ItemResponseData $i): bool => $i->dimension === Dimension::Validity && $i->monthSlot === 3))->toBeFalse();
});
