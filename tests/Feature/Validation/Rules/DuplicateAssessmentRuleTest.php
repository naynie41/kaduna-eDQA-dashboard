<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Round\Models\Round;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\DuplicateAssessmentRule;
use App\Domain\Validation\ValidationContextFactory;
use Tests\Support\ParsedSubmissionBuilder;

/** @return array{0: Round, 1: Facility, 2: string} the round, facility and the existing assessment's instance ID */
function existingAssessment(): array
{
    $round = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    $facility = Facility::factory()->create(['code' => 'KD-0412']);
    $assessment = Assessment::factory()->forRound($round)->create(['facility_id' => $facility->id]);

    return [$round, $facility, (string) Submission::query()->whereKey($assessment->submission_id)->value('instance_id')];
}

it('is a hard rule that needs the parse', function (): void {
    $rule = app(DuplicateAssessmentRule::class);

    expect($rule->code())->toBe('DUPLICATE_ASSESSMENT')
        ->and($rule->severity())->toBe(Severity::Hard)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes the first assessment of a facility in a round', function (): void {
    expect(checkRule(DuplicateAssessmentRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([]);
});

it('fails a second assessment of the same facility in the same round', function (): void {
    [$round, $facility, $instance] = existingAssessment();

    $failures = checkRule(DuplicateAssessmentRule::class, ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->severity)->toBe(Severity::Hard)
        ->and($failures[0]->detail)->toBe("Q2 2026 already recorded for facility KD-0412 (instance {$instance})")
        ->and($failures[0]->field)->toBe('facility_code');
});

it('passes an ODK edit whose deprecatedID is the existing assessment\'s submission', function (): void {
    [$round, $facility, $instance] = existingAssessment();

    $edit = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->withDeprecatedId($instance)->build();

    expect(checkRule(DuplicateAssessmentRule::class, $edit))->toBe([]);
});

it('fails an edit that supersedes some other submission', function (): void {
    [$round, $facility] = existingAssessment();

    $edit = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->withDeprecatedId('uuid:unrelated')->build();

    expect(checkRule(DuplicateAssessmentRule::class, $edit))->toHaveCount(1);
});

it('passes the same facility in another round', function (): void {
    [, $facility] = existingAssessment();
    $q3 = Round::factory()->create(['year' => 2026, 'quarter' => 3]);

    expect(checkRule(DuplicateAssessmentRule::class, ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($q3)->build()))->toBe([]);
});

it('catches a duplicate arriving in the same batch', function (): void {
    $round = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    $facility = Facility::factory()->create(['code' => 'KD-0412']);
    $first = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();
    $second = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();
    $context = app(ValidationContextFactory::class)->forBatch([$first, $second]);
    $rule = app(DuplicateAssessmentRule::class);

    expect($rule->check($first, $context))->toBe([]);
    $context->registerAccepted($first);

    expect($rule->check($second, $context)[0]->detail ?? null)
        ->toBe("Q2 2026 already recorded for facility KD-0412 (instance {$first->instanceId})");
});

it('leaves an unresolved facility or round to their own rules', function (): void {
    expect(checkRule(DuplicateAssessmentRule::class, ParsedSubmissionBuilder::new()->withFacilityRef('2019.00')->build()))->toBe([]);
});
