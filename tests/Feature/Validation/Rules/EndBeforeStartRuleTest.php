<?php

declare(strict_types=1);

use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\EndBeforeStartRule;
use Carbon\CarbonImmutable;
use Tests\Support\ParsedSubmissionBuilder;

it('is a hard rule that needs the parse', function (): void {
    $rule = app(EndBeforeStartRule::class);

    expect($rule->code())->toBe('END_BEFORE_START')
        ->and($rule->severity())->toBe(Severity::Hard)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes a visit that ends after it starts', function (): void {
    expect(checkRule(EndBeforeStartRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([]);
});

it('fails a visit that ends before it starts, in local time', function (): void {
    $submission = ParsedSubmissionBuilder::new()
        ->withStartedAt(CarbonImmutable::parse('2026-04-14T10:00:00+01:00'))
        ->withEndedAt(CarbonImmutable::parse('2026-04-14T08:15:00+00:00'))
        ->build();

    $failures = checkRule(EndBeforeStartRule::class, $submission);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->severity)->toBe(Severity::Hard)
        ->and($failures[0]->detail)->toBe('visit ended 2026-04-14 09:15, before it started 2026-04-14 10:00')
        ->and($failures[0]->field)->toBe('ended_at');
});

it('passes a visit that ends the moment it starts', function (): void {
    $at = CarbonImmutable::parse('2026-04-14T10:00:00+01:00');

    expect(checkRule(EndBeforeStartRule::class, ParsedSubmissionBuilder::new()->withStartedAt($at)->withEndedAt($at)->build()))->toBe([]);
});

it('compares instants, not clock readings, across time zones', function (): void {
    // 09:30 UTC is 10:30 in Lagos: after a 10:00 Lagos start.
    $submission = ParsedSubmissionBuilder::new()
        ->withStartedAt(CarbonImmutable::parse('2026-04-14T10:00:00+01:00'))
        ->withEndedAt(CarbonImmutable::parse('2026-04-14T09:30:00+00:00'))
        ->build();

    expect(checkRule(EndBeforeStartRule::class, $submission))->toBe([]);
});

it('leaves an unparsed start or end to COLUMN_DRIFT', function (?string $missing): void {
    $builder = ParsedSubmissionBuilder::new();
    $missing === 'start' ? $builder->withStartedAt(null) : $builder->withEndedAt(null);

    expect(checkRule(EndBeforeStartRule::class, $builder->build()))->toBe([]);
})->with(['start', 'end']);
