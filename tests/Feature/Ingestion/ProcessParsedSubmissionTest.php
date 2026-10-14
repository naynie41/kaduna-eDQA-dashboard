<?php

declare(strict_types=1);

use App\Domain\Assessment\Events\AssessmentScored;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Ward;
use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Ingestion\Actions\ProcessParsedSubmission;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\DTOs\ProcessOutcome;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Events\SubmissionQuarantined;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Domain\Validation\Rules\ColumnDriftRule;
use App\Domain\Validation\Rules\LgaUnknownRule;
use App\Domain\Validation\ValidationContextFactory;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ParsedSubmissionBuilder;
use Tests\Support\ThrowingRule;

beforeEach(function (): void {
    $this->version = ScoringRuleVersion::factory()->published()->create();
});

function processOne(ParsedSubmission $parsed): ProcessOutcome
{
    $submission = submissionRow($parsed);
    $context = app(ValidationContextFactory::class)->forBatch([$parsed]);

    return app(ProcessParsedSubmission::class)->execute($submission, $parsed, $context);
}

/**
 * @param  list<ParsedSubmission>  $parsed
 * @return list<ProcessOutcome>
 */
function processBatch(array $parsed): array
{
    return app(ProcessParsedBatch::class)->execute(array_map(fn (ParsedSubmission $p): array => [submissionRow($p), $p], $parsed));
}

// ---- Accepted

it('turns a valid submission into one assessment, its item responses and nine scores', function (): void {
    Event::fake([AssessmentScored::class]);
    $parsed = ParsedSubmissionBuilder::new()->build();

    $outcome = processOne($parsed);

    $assessment = Assessment::query()->sole();
    expect($outcome->status)->toBe(SubmissionStatus::Accepted)
        ->and($outcome->assessmentId)->toBe($assessment->id)
        ->and($assessment->facility->code)->toBe($parsed->facilityRef)
        ->and($assessment->status)->toBe('accepted')
        ->and($assessment->flags)->toBe([])
        ->and($assessment->assessor_name)->toBe('Hauwa Ibrahim')
        ->and($assessment->started_at->equalTo($parsed->startedAt))->toBeTrue();

    $items = ItemResponse::query()->where('assessment_id', $assessment->id)->get();
    expect($items)->toHaveCount(36)
        ->and($items->where('value', 'pass'))->toHaveCount(27)
        ->and($items->where('value', 'fail'))->toHaveCount(9);

    $scores = AssessmentScore::query()->where('assessment_id', $assessment->id)->get();
    expect($scores)->toHaveCount(9)
        ->and($scores->pluck('rule_version_id')->unique()->all())->toBe([$this->version->id])
        ->and($scores->pluck('score')->unique()->all())->toBe(['75.00']);

    $submission = Submission::query()->where('instance_id', $parsed->instanceId)->sole();
    expect($submission->status)->toBe(SubmissionStatus::Accepted)
        ->and($submission->processed_at)->not->toBeNull()
        ->and(QuarantinedRecord::query()->count())->toBe(0);

    Event::assertDispatched(AssessmentScored::class, fn (AssessmentScored $e): bool => $e->assessmentId === $assessment->id && $e->ruleVersionId === $this->version->id);
});

it('stores a null score for an all-N/A slot and notes the exclusion in flags', function (): void {
    $builder = ParsedSubmissionBuilder::new();
    foreach (ParsedSubmissionBuilder::ITEM_CODES as $code) {
        $builder->withItemValue(Dimension::Validity, 3, $code, 'na');
    }

    processOne($builder->build());

    $assessment = Assessment::query()->sole();
    expect(AssessmentScore::query()->where('dimension', 'validity')->where('month_slot', 3)->value('score'))->toBeNull()
        ->and(AssessmentScore::query()->count())->toBe(9)
        ->and($assessment->flags)->toBe(['SLOT_ALL_NA:validity:3']);
});

it('audits the new assessment', function (): void {
    processOne(ParsedSubmissionBuilder::new()->build());

    expect(Activity::query()->forSubject(Assessment::query()->sole())->where('event', 'created')->exists())->toBeTrue();
});

it('accepts a soft-only submission as flagged, listing the flags', function (): void {
    $outcome = processOne(ParsedSubmissionBuilder::new()->withVisitMinutes(12)->build());

    $assessment = Assessment::query()->sole();
    expect($outcome->status)->toBe(SubmissionStatus::Flagged)
        ->and($assessment->status)->toBe('flagged')
        ->and($assessment->flags)->toBe(['VISIT_TOO_SHORT'])
        ->and(AssessmentScore::query()->count())->toBe(9)
        ->and(Submission::query()->sole()->status)->toBe(SubmissionStatus::Flagged);
});

it('refuses to accept anything when no rule version is published', function (): void {
    ScoringRuleVersion::query()->update(['published_at' => null, 'published_reason' => null]);

    expect(fn () => processOne(ParsedSubmissionBuilder::new()->build()))->toThrow(NoPublishedRuleVersion::class)
        ->and(Assessment::query()->count())->toBe(0);
});

// ---- Quarantined

it('quarantines a hard failure: no assessment, no items, no scores', function (): void {
    Event::fake([SubmissionQuarantined::class, AssessmentScored::class]);
    $parsed = ParsedSubmissionBuilder::new()->withLga(null)->build();

    $outcome = processOne($parsed);

    $record = QuarantinedRecord::query()->sole();
    expect($outcome->status)->toBe(SubmissionStatus::Quarantined)
        ->and($outcome->quarantinedRecordId)->toBe($record->id)
        ->and(Assessment::query()->count())->toBe(0)
        ->and(ItemResponse::query()->count())->toBe(0)
        ->and(AssessmentScore::query()->count())->toBe(0)
        ->and($record->status)->toBe(QuarantineStatus::Open)
        ->and($record->instance_id)->toBe($parsed->instanceId)
        ->and($record->lga_ref)->toBeNull()
        ->and($record->facility_ref)->toBe($parsed->facilityRef)
        ->and($record->round_id)->not->toBeNull()
        ->and($record->payload)->toEqual(Submission::query()->sole()->payload)
        ->and($record->parsed)->toEqual($parsed->toArray())
        ->and($record->corrections)->toBe([])
        ->and($record->failures)->toEqual([['code' => 'LGA_UNKNOWN', 'severity' => 'hard', 'detail' => 'lga was blank', 'field' => 'lga']])
        ->and(Submission::query()->sole()->status)->toBe(SubmissionStatus::Quarantined);

    Event::assertDispatched(SubmissionQuarantined::class, fn (SubmissionQuarantined $e): bool => $e->quarantinedRecordId === $record->id);
    Event::assertNotDispatched(AssessmentScored::class);
});

it('records every failure, hard and soft, on the quarantined record', function (): void {
    processOne(ParsedSubmissionBuilder::new()->withWard('Quarterly')->withFacilityRef('2019.00')->withVisitMinutes(12)->build());

    expect(array_column(QuarantinedRecord::query()->sole()->failures, 'code'))
        ->toBe(['COLUMN_DRIFT', 'FACILITY_UNKNOWN', 'VISIT_TOO_SHORT']);
});

it('quarantines with RULE_ERROR when a rule throws', function (): void {
    config(['edqa.validation.rules' => ['COLUMN_DRIFT' => ColumnDriftRule::class, 'BROKEN_RULE' => ThrowingRule::class, 'LGA_UNKNOWN' => LgaUnknownRule::class]]);
    Log::shouldReceive('channel')->with('edqa')->andReturn(Mockery::spy(LoggerInterface::class));

    $outcome = processOne(ParsedSubmissionBuilder::new()->build());

    expect($outcome->status)->toBe(SubmissionStatus::Quarantined)
        ->and(Assessment::query()->count())->toBe(0)
        ->and(array_column(QuarantinedRecord::query()->sole()->failures, 'code'))->toBe(['RULE_ERROR']);
});

it('dispatches its events only after the transaction commits', function (): void {
    expect(new AssessmentScored(1, 1, 1))->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and(new SubmissionQuarantined(1, 1))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

// ---- Batches

it('catches a duplicate inside one batch: the first is accepted, the second quarantined', function (): void {
    $facility = Facility::factory()->create();
    $round = Round::factory()->create();
    $first = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();
    $second = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();

    $outcomes = processBatch([$first, $second]);

    expect(array_map(fn (ProcessOutcome $o) => $o->status, $outcomes))->toBe([SubmissionStatus::Accepted, SubmissionStatus::Quarantined])
        ->and(Assessment::query()->count())->toBe(1)
        ->and(array_column(QuarantinedRecord::query()->sole()->failures, 'code'))->toBe(['DUPLICATE_ASSESSMENT']);
});

it('updates the assessment in place for an ODK edit, audited, and supersedes the original', function (): void {
    $facility = Facility::factory()->create();
    $round = Round::factory()->create();
    $original = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();
    processBatch([$original]);
    $assessment = Assessment::query()->sole();

    $edit = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)
        ->withDeprecatedId($original->instanceId)->withAssessor('Hauwa Ibrahim-Musa')->withAllItems('fail')->build();
    $outcomes = processBatch([$edit]);

    $updated = Assessment::query()->sole();
    $old = Submission::query()->where('instance_id', $original->instanceId)->sole();
    $new = Submission::query()->where('instance_id', $edit->instanceId)->sole();
    expect($outcomes[0]->status)->toBe(SubmissionStatus::Accepted)
        ->and($outcomes[0]->supersededSubmissionId)->toBe($old->id)
        ->and($updated->id)->toBe($assessment->id)
        ->and($updated->submission_id)->toBe($new->id)
        ->and($updated->assessor_name)->toBe('Hauwa Ibrahim-Musa')
        ->and($old->status)->toBe(SubmissionStatus::Superseded)
        ->and($old->superseded_by_id)->toBe($new->id)
        ->and(ItemResponse::query()->count())->toBe(36)
        ->and(ItemResponse::query()->where('value', 'fail')->count())->toBe(36)
        ->and(AssessmentScore::query()->count())->toBe(9)
        ->and(AssessmentScore::query()->pluck('score')->unique()->all())->toBe(['0.00']);

    $entry = Activity::query()->forSubject($updated)->where('event', 'updated')->sole();
    expect($entry->properties['old'])->toMatchArray(['assessor_name' => 'Hauwa Ibrahim', 'submission_id' => $old->id])
        ->and($entry->properties['attributes'])->toMatchArray(['assessor_name' => 'Hauwa Ibrahim-Musa', 'submission_id' => $new->id]);

    $scoreChange = Activity::query()->where('subject_type', (new AssessmentScore)->getMorphClass())->where('event', 'updated')->first();
    expect($scoreChange?->properties['old']['score'] ?? null)->toBe('75.00')
        ->and($scoreChange?->properties['attributes']['score'] ?? null)->toBe('0.00');
});

it('leaves the original untouched when the edit itself fails validation', function (): void {
    $facility = Facility::factory()->create();
    $round = Round::factory()->create();
    $original = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();
    processBatch([$original]);

    processBatch([ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->withDeprecatedId($original->instanceId)->withLga(null)->build()]);

    expect(Submission::query()->where('instance_id', $original->instanceId)->sole()->status)->toBe(SubmissionStatus::Accepted)
        ->and(Assessment::query()->sole()->assessor_name)->toBe('Hauwa Ibrahim')
        ->and(QuarantinedRecord::query()->count())->toBe(1);
});

it('keeps the queries per submission constant across a 200-submission batch', function (): void {
    $round = Round::factory()->create();
    $ward = Ward::factory()->create();
    $build = fn (int $n): array => Facility::factory()->count($n)->inWard($ward)->create()
        ->map(fn (Facility $f) => ParsedSubmissionBuilder::new()->forFacility($f)->inRound($round)->withAssessor('Assessor '.$f->id)->build())->all();
    $count = function (array $batch): int {
        $pairs = array_map(fn (ParsedSubmission $p): array => [submissionRow($p), $p], $batch);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ProcessParsedBatch::class)->execute($pairs);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $ten = $count($build(10));
    $twoHundred = $count($build(200));

    // Marginal cost per submission: one-off work (a cold rule-version cache) cancels out.
    $perSubmission = ($twoHundred - $ten) / 190;
    // Linear in the batch size: no query grows with the number of submissions already processed.
    expect($twoHundred)->toBeLessThanOrEqual($ten + 190 * 32)
        // 1 assessment, 1 items insert, 9 scores, 10 audit entries (+ the re-read activitylog does
        // after each), 1 submission update. 27 of the 32 are per-score audit (Q-20).
        ->and($perSubmission)->toBeLessThanOrEqual(32)
        ->and(Assessment::query()->count())->toBe(210);
});
