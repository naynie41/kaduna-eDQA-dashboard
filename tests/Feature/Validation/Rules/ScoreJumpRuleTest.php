<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Facility;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\ScoreJumpRule;
use Tests\Support\ParsedSubmissionBuilder;

// The builder's default submission scores 75 overall (3 of 4 items pass in every slot).

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $this->facility = Facility::factory()->create();
    $this->round = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
});

function priorOverall(Facility $facility, int $year, int $quarter, float $overall): void
{
    Assessment::factory()->forRound(Round::factory()->closed()->create(['year' => $year, 'quarter' => $quarter]))
        ->withScores(['availability' => $overall, 'consistency' => $overall, 'validity' => $overall])
        ->create(['facility_id' => $facility->id]);
}

it('is a soft rule that needs the parse', function (): void {
    $rule = app(ScoreJumpRule::class);

    expect($rule->code())->toBe('SCORE_JUMP')
        ->and($rule->severity())->toBe(Severity::Soft)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes a facility with no earlier round', function (): void {
    expect(checkRule(ScoreJumpRule::class, ParsedSubmissionBuilder::new()->forFacility($this->facility)->inRound($this->round)->build()))->toBe([]);
});

it('passes a move of exactly the threshold', function (): void {
    priorOverall($this->facility, 2026, 1, 45); // 75 − 45 = 30

    expect(checkRule(ScoreJumpRule::class, ParsedSubmissionBuilder::new()->forFacility($this->facility)->inRound($this->round)->build()))->toBe([]);
});

it('flags a move one point over the threshold', function (): void {
    priorOverall($this->facility, 2026, 1, 44); // 75 − 44 = 31

    $failures = checkRule(ScoreJumpRule::class, ParsedSubmissionBuilder::new()->forFacility($this->facility)->inRound($this->round)->build());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->code)->toBe('SCORE_JUMP')
        ->and($failures[0]->severity)->toBe(Severity::Soft)
        ->and($failures[0]->detail)->toBe('overall 75.0, previous round 44.0 (+31.0)')
        ->and($failures[0]->field)->toBe('overall');
});

it('flags a drop, with a minus sign, against the configured threshold', function (): void {
    config(['edqa.validation.soft_rules.score_jump_points' => 20]);
    priorOverall($this->facility, 2026, 1, 100);

    expect(checkRule(ScoreJumpRule::class, ParsedSubmissionBuilder::new()->forFacility($this->facility)->inRound($this->round)->build())[0]->detail ?? null)
        ->toBe('overall 75.0, previous round 100.0 (−25.0)');
});

it('compares with the most recent earlier round only', function (): void {
    priorOverall($this->facility, 2025, 4, 10);  // a 65-point jump, but older
    priorOverall($this->facility, 2026, 1, 70);

    expect(checkRule(ScoreJumpRule::class, ParsedSubmissionBuilder::new()->forFacility($this->facility)->inRound($this->round)->build()))->toBe([]);
});

it('stays silent when the submission cannot be scored', function (): void {
    priorOverall($this->facility, 2026, 1, 0);
    $builder = ParsedSubmissionBuilder::new()->forFacility($this->facility)->inRound($this->round);
    foreach ([1, 2, 3] as $month) {
        $builder->withoutSlot(Dimension::Validity, $month);
    }

    expect(checkRule(ScoreJumpRule::class, $builder->build()))->toBe([]);
});
