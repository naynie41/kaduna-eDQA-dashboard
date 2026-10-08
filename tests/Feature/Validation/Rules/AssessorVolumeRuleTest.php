<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Facility;
use App\Domain\Round\Models\Round;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\AssessorVolumeRule;
use App\Domain\Validation\ValidationContextFactory;
use Carbon\CarbonImmutable;
use Tests\Support\ParsedSubmissionBuilder;

// The builder's visits fall on 2026-04-14 in a Q2 2026 round.

beforeEach(function (): void {
    $this->round = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
});

/** Earlier visits that day, each to a different facility. */
function visitsThatDay(Round $round, int $count, string $assessor = 'A. Bello', ?string $device = 'collect:p1'): void
{
    Assessment::factory()->forRound($round)->count($count)->create([
        'assessor_name' => $assessor,
        'device_id' => $device,
        'started_at' => CarbonImmutable::parse('2026-04-14 08:00', 'Africa/Lagos'),
    ]);
}

function visitBy(Round $round, string $assessor = 'A. Bello', ?string $device = 'collect:p1'): ParsedSubmissionBuilder
{
    return ParsedSubmissionBuilder::new()->inRound($round)->withAssessor($assessor)->withDeviceId($device);
}

it('is a soft rule that needs the parse', function (): void {
    $rule = app(AssessorVolumeRule::class);

    expect($rule->code())->toBe('ASSESSOR_VOLUME')
        ->and($rule->severity())->toBe(Severity::Soft)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it("passes an assessor's first visit of the day", function (): void {
    expect(checkRule(AssessorVolumeRule::class, visitBy($this->round)->build()))->toBe([]);
});

it('passes exactly the maximum, counting this visit', function (): void {
    visitsThatDay($this->round, 5); // + this one = 6

    expect(checkRule(AssessorVolumeRule::class, visitBy($this->round)->build()))->toBe([]);
});

it('flags one visit over the maximum', function (): void {
    visitsThatDay($this->round, 6); // + this one = 7

    $failures = checkRule(AssessorVolumeRule::class, visitBy($this->round)->build());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->severity)->toBe(Severity::Soft)
        ->and($failures[0]->detail)->toBe('assessor A. Bello logged 7 facilities on 2026-04-14')
        ->and($failures[0]->field)->toBe('assessor');
});

it('counts visits accepted earlier in the same batch', function (): void {
    visitsThatDay($this->round, 5);
    $sixth = visitBy($this->round)->build();
    $seventh = visitBy($this->round)->build();
    $context = app(ValidationContextFactory::class)->forBatch([$sixth, $seventh]);
    $rule = app(AssessorVolumeRule::class);

    expect($rule->check($sixth, $context))->toBe([]);
    $context->registerAccepted($sixth);

    expect($rule->check($seventh, $context)[0]->detail ?? null)->toBe('assessor A. Bello logged 7 facilities on 2026-04-14');
});

it('does not count a second visit to the same facility twice', function (): void {
    visitsThatDay($this->round, 5);
    $facility = Facility::factory()->create();
    $first = visitBy($this->round)->forFacility($facility)->build();
    $again = visitBy($this->round)->forFacility($facility)->build();
    $context = app(ValidationContextFactory::class)->forBatch([$first, $again]);
    $context->registerAccepted($first);

    expect(app(AssessorVolumeRule::class)->check($again, $context))->toBe([]);
});

it('matches the name ignoring case and spacing, but tells devices apart', function (): void {
    visitsThatDay($this->round, 6, 'a.  BELLO', 'collect:p1');

    expect(checkRule(AssessorVolumeRule::class, visitBy($this->round, 'A. Bello', 'collect:p1')->build()))->toHaveCount(1)
        ->and(checkRule(AssessorVolumeRule::class, visitBy($this->round, 'A. Bello', 'collect:p2')->build()))->toBe([]);
});

it('goes by name alone when there is no device ID', function (): void {
    visitsThatDay($this->round, 6, 'A. Bello', null);

    expect(checkRule(AssessorVolumeRule::class, visitBy($this->round, 'A. Bello', null)->build()))->toHaveCount(1);
});

it('reads the maximum from config', function (): void {
    config(['edqa.validation.soft_rules.assessor_max_per_day' => 2]);
    visitsThatDay($this->round, 2);

    expect(checkRule(AssessorVolumeRule::class, visitBy($this->round)->build())[0]->detail ?? null)
        ->toBe('assessor A. Bello logged 3 facilities on 2026-04-14');
});
