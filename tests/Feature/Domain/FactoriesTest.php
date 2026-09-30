<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use App\Domain\Ingestion\Models\OdkAttachment;
use App\Domain\Ingestion\Models\OdkFormSync;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Plan\Models\PlanAction;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Models\QuarantinedRecord;

it('builds a valid row for every model', function (string $model): void {
    expect($model::factory()->create()->exists)->toBeTrue();
})->with([
    Lga::class, Ward::class, Facility::class, Round::class, Submission::class, Assessment::class,
    ItemResponse::class, AssessmentScore::class, QuarantinedRecord::class, ScoringRuleVersion::class,
    PlanAction::class, OdkFormSync::class, OdkPullRun::class, OdkAttachment::class,
]);

it('keeps a facility in the same LGA as its ward', function (): void {
    $facility = Facility::factory()->create();

    expect($facility->lga_id)->toBe($facility->ward->lga_id);
});

it('has private, inactive and closed states', function (): void {
    expect(Facility::factory()->private()->create()->ownership)->toBe(Ownership::Private)
        ->and(Facility::factory()->inactive()->create()->is_active)->toBeFalse()
        ->and(Round::factory()->closed()->create())
        ->status->toBe(RoundStatus::Closed)
        ->closed_at->not->toBeNull();
});

it('places an assessment inside its round window', function (): void {
    $round = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    $assessment = Assessment::factory()->forRound($round)->create();

    expect($assessment->round_id)->toBe($round->id)
        ->and($assessment->started_at->toDateString())
        ->toBeGreaterThanOrEqual($round->window_start->toDateString())
        ->toBeLessThanOrEqual($round->window_end->toDateString())
        ->and($assessment->ended_at->greaterThanOrEqualTo($assessment->started_at))->toBeTrue();
});

// CLAUDE.md hard rule 2: scores are derived from item responses, never typed.
it('derives every score from the item pass counts', function (): void {
    $assessment = Assessment::factory()
        ->withScores(['availability' => 90, 'consistency' => [80, 87.5, null], 'validity' => 100])
        ->create();

    foreach ($assessment->scores as $score) {
        $items = $assessment->itemResponses
            ->where('dimension', $score->dimension)
            ->where('month_slot', $score->month_slot);
        $applicable = $items->where('is_applicable', true);
        $passed = $applicable->where('value', 'yes')->count();
        $derived = $applicable->isEmpty() ? null : round($passed / $applicable->count() * 100, 2);

        expect($score->score === null ? null : (float) $score->score)->toBe($derived);
    }

    $consistency = $assessment->scores->where('dimension', Dimension::Consistency)->sortBy('month_slot')->pluck('score')->all();
    expect($consistency)->toBe(['80.00', '87.50', null]);
});

it('cannot produce a score outside 0 to 100', function (): void {
    expect(fn () => Assessment::factory()->withScores(['availability' => 347.66])->create())
        ->toThrow(InvalidArgumentException::class);
});

it('builds a quarantined record failing a given rule', function (): void {
    $record = QuarantinedRecord::factory()->failing('SCORE_RANGE')->create();

    expect($record->status)->toBe(QuarantineStatus::Open)
        ->and($record->failures)->toHaveCount(1)
        ->and($record->failures[0])->toMatchArray(['code' => 'SCORE_RANGE', 'severity' => 'hard'])
        ->and($record->instance_id)->toBe($record->submission->instance_id);
});
