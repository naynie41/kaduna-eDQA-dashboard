<?php

declare(strict_types=1);

use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\VisitTooShortRule;
use Tests\Support\ParsedSubmissionBuilder;

it('is a soft rule that needs the parse', function (): void {
    $rule = app(VisitTooShortRule::class);

    expect($rule->code())->toBe('VISIT_TOO_SHORT')
        ->and($rule->severity())->toBe(Severity::Soft)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes a 45-minute visit', function (): void {
    expect(checkRule(VisitTooShortRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([]);
});

it('passes a visit of exactly the minimum', function (): void {
    expect(checkRule(VisitTooShortRule::class, ParsedSubmissionBuilder::new()->withVisitMinutes(20)->build()))->toBe([]);
});

it('flags a visit one minute under the minimum', function (): void {
    $failures = checkRule(VisitTooShortRule::class, ParsedSubmissionBuilder::new()->withVisitMinutes(19)->build());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->severity)->toBe(Severity::Soft)
        ->and($failures[0]->detail)->toBe('visit lasted 19 minutes (minimum 20)')
        ->and($failures[0]->field)->toBe('ended_at');
});

it('flags a visit seconds under the minimum, counting whole minutes down', function (): void {
    $submission = ParsedSubmissionBuilder::new()->build();
    $short = ParsedSubmissionBuilder::new()->withStartedAt($submission->startedAt)
        ->withEndedAt($submission->startedAt->addMinutes(19)->addSeconds(59))->build();

    expect(checkRule(VisitTooShortRule::class, $short)[0]->detail ?? null)->toBe('visit lasted 19 minutes (minimum 20)');
});

it('reads the minimum from config', function (): void {
    config(['edqa.validation.soft_rules.visit_min_minutes' => 30]);

    expect(checkRule(VisitTooShortRule::class, ParsedSubmissionBuilder::new()->withVisitMinutes(25)->build())[0]->detail ?? null)
        ->toBe('visit lasted 25 minutes (minimum 30)');
});

it('leaves a visit that ends before it starts to END_BEFORE_START', function (): void {
    $submission = ParsedSubmissionBuilder::new()->build();
    $backwards = ParsedSubmissionBuilder::new()->withEndedAt($submission->startedAt->subMinutes(5))->build();

    expect(checkRule(VisitTooShortRule::class, $backwards))->toBe([]);
});

it('skips a visit with a time that did not parse', function (string $missing): void {
    $builder = ParsedSubmissionBuilder::new();
    $missing === 'start' ? $builder->withStartedAt(null) : $builder->withEndedAt(null);

    expect(checkRule(VisitTooShortRule::class, $builder->build()))->toBe([]);
})->with(['start', 'end']);
