<?php

declare(strict_types=1);

use App\Domain\Round\Models\Round;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\RoundWindowRule;
use Carbon\CarbonImmutable;
use Tests\Support\ParsedSubmissionBuilder;

it('is a hard rule that needs the parse', function (): void {
    $rule = app(RoundWindowRule::class);

    expect($rule->code())->toBe('ROUND_WINDOW')
        ->and($rule->severity())->toBe(Severity::Hard)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes a visit inside an open round that matches the form', function (): void {
    expect(checkRule(RoundWindowRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([]);
});

it('fails a visit no round covers, never guessing the nearest', function (): void {
    $q2 = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    // 1 July: the day after Q2 ends, and no Q3 round exists.
    $submission = ParsedSubmissionBuilder::new()->inRound($q2)->withStartedAt(CarbonImmutable::parse('2026-07-01 09:00'))->build();

    $failures = checkRule(RoundWindowRule::class, $submission);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->severity)->toBe(Severity::Hard)
        ->and($failures[0]->detail)->toBe('visit date 2026-07-01 is not inside any round\'s window')
        ->and($failures[0]->field)->toBe('started_at');
});

it('fails a visit in a closed round', function (): void {
    $q1 = Round::factory()->closed()->create(['year' => 2026, 'quarter' => 1]);

    $failures = checkRule(RoundWindowRule::class, ParsedSubmissionBuilder::new()->inRound($q1)->build());

    expect($failures[0]->detail ?? null)->toBe('visit date 2026-01-14 falls in Q1 2026, which is closed');
});

it('fails when the form names a different round from the visit date', function (?int $year, ?int $quarter, string $said): void {
    $q2 = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    Round::factory()->create(['year' => 2026, 'quarter' => 3]);

    $failures = checkRule(RoundWindowRule::class, ParsedSubmissionBuilder::new()->inRound($q2)->withRoundRef($year, $quarter)->build());

    expect($failures[0]->detail ?? null)->toBe("the form says {$said}, but visit date 2026-04-14 falls in Q2 2026")
        ->and($failures[0]->field ?? null)->toBe('round');
})->with([
    'next quarter' => [2026, 3, 'Q3 2026'],
    'same quarter, wrong year' => [2025, 2, 'Q2 2025'],
]);

it('lets a form that does not name its round pass on the visit date alone', function (?int $year, ?int $quarter): void {
    expect(checkRule(RoundWindowRule::class, ParsedSubmissionBuilder::new()->withRoundRef($year, $quarter)->build()))->toBe([]);
})->with([
    'neither' => [null, null],
    'year only' => [2026, null],
]);

it('reports both a closed round and a disagreeing form', function (): void {
    $q1 = Round::factory()->closed()->create(['year' => 2026, 'quarter' => 1]);

    $failures = checkRule(RoundWindowRule::class, ParsedSubmissionBuilder::new()->inRound($q1)->withRoundRef(2026, 2)->build());

    expect(array_map(fn ($f) => $f->detail, $failures))->toBe([
        'visit date 2026-01-14 falls in Q1 2026, which is closed',
        'the form says Q2 2026, but visit date 2026-01-14 falls in Q1 2026',
    ]);
});

it('fails a date inside two overlapping windows rather than pick one', function (): void {
    $q2 = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    Round::factory()->create(['year' => 2026, 'quarter' => 3, 'window_start' => '2026-06-20', 'window_end' => '2026-09-30']);
    $submission = ParsedSubmissionBuilder::new()->inRound($q2)->withRoundRef(null, null)
        ->withStartedAt(CarbonImmutable::parse('2026-06-25 09:00'))->build();

    expect(checkRule(RoundWindowRule::class, $submission)[0]->detail ?? null)
        ->toBe('visit date 2026-06-25 falls inside more than one round\'s window (Q2 2026, Q3 2026)');
});

it('uses the submission time when the start did not parse', function (): void {
    $q2 = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    $submission = ParsedSubmissionBuilder::new()->inRound($q2)->withStartedAt(null)->build();

    expect(checkRule(RoundWindowRule::class, $submission))->toBe([]);
});
