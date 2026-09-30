<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use App\Domain\Ingestion\Models\OdkAttachment;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Plan\Models\PlanAction;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;

it('links LGAs, wards and facilities', function (): void {
    $facility = Facility::factory()->create();

    expect($facility->ward)->toBeInstanceOf(Ward::class)
        ->and($facility->lga)->toBeInstanceOf(Lga::class)
        ->and($facility->ward->lga->is($facility->lga))->toBeTrue()
        ->and($facility->lga->facilities->pluck('id')->all())->toBe([$facility->id])
        ->and($facility->lga->wards->pluck('id')->all())->toBe([$facility->ward_id])
        ->and($facility->ward->facilities->pluck('id')->all())->toBe([$facility->id]);
});

it('links an assessment to its round, facility, submission, items and scores', function (): void {
    $assessment = Assessment::factory()->withScores(['availability' => 90, 'consistency' => 80, 'validity' => 100])->create()
        ->load('scores.ruleVersion', 'round.assessments', 'facility.assessments', 'submission.assessment');

    expect($assessment->round)->toBeInstanceOf(Round::class)
        ->and($assessment->facility)->toBeInstanceOf(Facility::class)
        ->and($assessment->submission)->toBeInstanceOf(Submission::class)
        ->and($assessment->submission->assessment->is($assessment))->toBeTrue()
        ->and($assessment->itemResponses)->not->toBeEmpty()
        ->and($assessment->itemResponses->first())->toBeInstanceOf(ItemResponse::class)
        ->and($assessment->scores)->toHaveCount(9)
        ->and($assessment->scores->first())->toBeInstanceOf(AssessmentScore::class)
        ->and($assessment->scores->first()->ruleVersion)->toBeInstanceOf(ScoringRuleVersion::class)
        ->and($assessment->round->assessments->pluck('id')->all())->toBe([$assessment->id])
        ->and($assessment->facility->assessments->pluck('id')->all())->toBe([$assessment->id]);
});

it('links a quarantined record to its submission, round and resolver', function (): void {
    $resolver = User::factory()->create();
    $record = QuarantinedRecord::factory()->create(['round_id' => Round::factory(), 'resolved_by' => $resolver->id]);

    expect($record->submission)->toBeInstanceOf(Submission::class)
        ->and($record->submission->quarantinedRecords->pluck('id')->all())->toBe([$record->id])
        ->and($record->round)->toBeInstanceOf(Round::class)
        ->and($record->round->quarantinedRecords->pluck('id')->all())->toBe([$record->id])
        ->and($record->resolvedBy->is($resolver))->toBeTrue();
});

it('links a superseded submission to its replacement, and attachments to a submission', function (): void {
    $replacement = Submission::factory()->create();
    $old = Submission::factory()->create(['superseded_by_id' => $replacement->id]);
    $attachment = OdkAttachment::factory()->create(['submission_id' => $replacement->id]);

    expect($old->supersededBy->is($replacement))->toBeTrue()
        ->and($attachment->submission->is($replacement))->toBeTrue()
        ->and($replacement->attachments->pluck('id')->all())->toBe([$attachment->id]);
});

it('links plan actions, rule versions and pull runs to their owners', function (): void {
    $action = PlanAction::factory()->create();
    $version = ScoringRuleVersion::factory()->create();
    $run = OdkPullRun::factory()->create(['triggered_by' => User::factory()]);

    expect($action->round)->toBeInstanceOf(Round::class)
        ->and($action->lga)->toBeInstanceOf(Lga::class)
        ->and($action->round->planActions->pluck('id')->all())->toBe([$action->id])
        ->and($action->lga->planActions->pluck('id')->all())->toBe([$action->id])
        ->and($version->creator)->toBeInstanceOf(User::class)
        ->and($run->triggeredBy)->toBeInstanceOf(User::class);
});
