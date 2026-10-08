<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Exceptions\SubmissionNotResolvable;
use App\Domain\Validation\ValidationContext;
use App\Domain\Validation\ValidationContextFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ParsedSubmissionBuilder;

function contextFor(array $submissions): ValidationContext
{
    return app(ValidationContextFactory::class)->forBatch($submissions);
}

function queriesWhile(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

// ---- Query budget (no N+1)

it('loads a batch in the same fixed number of queries for 1 or 500 submissions', function (): void {
    $round = Round::factory()->create();
    $ward = Ward::factory()->create();
    $facilities = Facility::factory()->count(500)->inWard($ward)->create();
    Assessment::factory()->forRound(Round::factory()->closed()->create())
        ->create(['facility_id' => $facilities[0]->id]);

    $batch = $facilities->map(fn (Facility $f) => ParsedSubmissionBuilder::new()
        ->forFacility($f)->inRound($round)->build())->all();

    $one = queriesWhile(fn () => contextFor([$batch[0]]));
    $many = queriesWhile(fn () => contextFor($batch));

    expect($one)->toBe(ValidationContextFactory::QUERY_COUNT)
        ->and($many)->toBe(ValidationContextFactory::QUERY_COUNT);
});

it('answers every lookup from memory, without further queries', function (): void {
    $submission = ParsedSubmissionBuilder::new()->build();
    $context = contextFor([$submission]);

    $queries = queriesWhile(function () use ($context, $submission): void {
        $context->lga($submission->lgaRef);
        $facility = $context->facility($submission->facilityRef);
        $round = $context->roundFor($submission);
        $context->duplicateOf($submission, $round->id, $facility->id);
        $context->priorOverallScore($facility->id, $round);
        $context->assessorVisitCount($submission->assessorName, $submission->visitDate());
        $context->isKnownFormVersion($submission->formVersion);
        $context->registerAccepted($submission);
    });

    expect($queries)->toBe(0);
});

it('loads nothing extra for an empty batch', function (): void {
    expect(queriesWhile(fn () => contextFor([])))->toBe(ValidationContextFactory::QUERY_COUNT);
});

// ---- LGAs

it('finds an LGA by code or by normalised name', function (?string $ref, bool $found): void {
    Lga::factory()->create(['code' => 'BGW', 'name' => 'Birnin Gwari']);

    $lga = contextFor([])->lga($ref);

    expect($lga?->code)->toBe($found ? 'BGW' : null);
})->with([
    'code' => ['BGW', true],
    'name' => ['Birnin Gwari', true],
    'name, other case and spacing' => ['  birnin   GWARI ', true],
    'name with a hyphen' => ['Birnin-Gwari', true],
    'unknown' => ['Kaduna East', false],
    'blank' => ['', false],
    'null' => [null, false],
]);

// ---- Facilities

it('finds a facility by exact code, telling active from inactive', function (): void {
    $active = Facility::factory()->create(['code' => 'KD/CHK/0042']);
    $inactive = Facility::factory()->inactive()->create(['code' => 'KD/CHK/0043']);
    $context = contextFor([
        ParsedSubmissionBuilder::new()->forFacility($active)->withFacilityRef(' KD/CHK/0042 ')->build(),
        ParsedSubmissionBuilder::new()->forFacility($inactive)->build(),
        ParsedSubmissionBuilder::new()->withFacilityRef('2019.00')->build(),
    ]);

    expect($context->facility(' KD/CHK/0042 ')?->id)->toBe($active->id)
        ->and($context->facility(' KD/CHK/0042 ')?->lgaId)->toBe($active->lga_id)
        ->and($context->activeFacility('KD/CHK/0043'))->toBeNull()
        ->and($context->facility('KD/CHK/0043')?->isActive)->toBeFalse()
        ->and($context->facility('2019.00'))->toBeNull()
        ->and($context->facility('kd/chk/0042'))->toBeNull()
        ->and($context->facility(null))->toBeNull();
});

// ---- Rounds

it('finds the round whose window holds the visit, open or closed', function (): void {
    $closed = Round::factory()->closed()->create(['year' => 2026, 'quarter' => 1]);
    $open = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    $inClosed = ParsedSubmissionBuilder::new()->inRound($closed)->build();
    $inOpen = ParsedSubmissionBuilder::new()->inRound($open)->build();
    $outside = ParsedSubmissionBuilder::new()->inRound($open)->withStartedAt(CarbonImmutable::parse('2026-08-01 09:00'))->build();
    $unparsed = ParsedSubmissionBuilder::new()->inRound($open)->withStartedAt(null)->build();

    $context = contextFor([$inClosed, $inOpen, $outside, $unparsed]);

    expect($context->roundFor($inClosed)?->id)->toBe($closed->id)
        ->and($context->roundFor($inClosed)?->isOpen())->toBeFalse()
        ->and($context->roundFor($inOpen)?->status)->toBe(RoundStatus::Open)
        ->and($context->roundFor($inOpen)?->isOpen())->toBeTrue()
        ->and($context->roundFor($outside))->toBeNull()
        // No parsed start: the submission time (5 minutes after the visit) places it instead.
        ->and($context->roundFor($unparsed)?->id)->toBe($open->id)
        ->and($context->round(2026, 2)?->id)->toBe($open->id)
        ->and($context->round(2026, 3))->toBeNull();
});

it('lists every round whose window holds a day, and picks none when windows overlap', function (): void {
    $q2 = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    // Overlapping windows are refused when rounds are created (§3); the context must not
    // trust that and guess between them.
    $overlap = Round::factory()->create(['year' => 2026, 'quarter' => 3, 'window_start' => '2026-06-20', 'window_end' => '2026-09-30']);
    $inBoth = ParsedSubmissionBuilder::new()->inRound($q2)->withStartedAt(CarbonImmutable::parse('2026-06-25 09:00'))->build();

    $context = contextFor([$inBoth]);

    expect(array_map(fn ($r) => $r->id, $context->roundsContaining($inBoth->visitDate())))->toBe([$q2->id, $overlap->id])
        ->and($context->roundFor($inBoth))->toBeNull();
});

// ---- Scoring rules (SCORE_RANGE)

it('holds the highest published rule version, ignoring drafts', function (): void {
    ScoringRuleVersion::factory()->published()->create();
    ScoringRuleVersion::factory()->published()->create(['config' => [
        'bands' => ['strong' => 95, 'acceptable' => 85, 'review' => 75], 'item_weights' => [],
        'na_policy' => 'exclude', 'choice_map' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
    ]]);
    ScoringRuleVersion::factory()->create(['config' => [
        'bands' => ['strong' => 99, 'acceptable' => 98, 'review' => 97], 'item_weights' => [],
        'na_policy' => 'exclude', 'choice_map' => ['yes' => 'pass'],
    ]]);

    expect(contextFor([])->ruleConfig()?->bands['strong'])->toBe(95.0);
});

it('holds no rule config when nothing is published', function (): void {
    ScoringRuleVersion::factory()->create();

    expect(contextFor([])->ruleConfig())->toBeNull();
});

it('counts a visit on the last day of the window, in local time', function (): void {
    $round = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    // 23:30 Lagos time on 30 June is 22:30 UTC: still inside Q2.
    $submission = ParsedSubmissionBuilder::new()->inRound($round)
        ->withStartedAt(CarbonImmutable::parse('2026-06-30T22:30:00+00:00'))->build();

    expect(contextFor([$submission])->roundFor($submission)?->id)->toBe($round->id);
});

// ---- Duplicates

it('reports an existing assessment for the same round and facility', function (): void {
    $round = Round::factory()->create();
    $facility = Facility::factory()->create();
    $existing = Assessment::factory()->forRound($round)->create(['facility_id' => $facility->id]);
    $instance = Submission::query()->whereKey($existing->submission_id)->value('instance_id');
    $submission = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();

    expect(contextFor([$submission])->duplicateOf($submission, $round->id, $facility->id))->toBe($instance);
});

it('does not count the assessment a submission supersedes, or its own, as a duplicate', function (): void {
    $round = Round::factory()->create();
    $facility = Facility::factory()->create();
    $existing = Assessment::factory()->forRound($round)->create(['facility_id' => $facility->id]);
    $instance = (string) Submission::query()->whereKey($existing->submission_id)->value('instance_id');
    $edit = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->withDeprecatedId($instance)->build();
    $reprocessed = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->withInstanceId($instance)->build();

    $context = contextFor([$edit, $reprocessed]);

    expect($context->duplicateOf($edit, $round->id, $facility->id))->toBeNull()
        ->and($context->duplicateOf($reprocessed, $round->id, $facility->id))->toBeNull();
});

it('catches a duplicate inside the same batch once the first is accepted', function (): void {
    $round = Round::factory()->create();
    $facility = Facility::factory()->create();
    $first = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();
    $second = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();
    $context = contextFor([$first, $second]);

    expect($context->duplicateOf($second, $round->id, $facility->id))->toBeNull();

    $context->registerAccepted($first);

    expect($context->duplicateOf($second, $round->id, $facility->id))->toBe($first->instanceId)
        ->and($context->duplicateOf($first, $round->id, $facility->id))->toBeNull();
});

it('refuses to register a submission it cannot place in a round and facility', function (): void {
    $submission = ParsedSubmissionBuilder::new()->withFacilityRef('2019.00')->build();

    expect(fn () => contextFor([$submission])->registerAccepted($submission))
        ->toThrow(SubmissionNotResolvable::class);
});

// ---- Prior scores (SCORE_JUMP)

it("gives a facility's overall score from its latest earlier round", function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $facility = Facility::factory()->create();
    $q4 = Round::factory()->closed()->create(['year' => 2025, 'quarter' => 4]);
    $q1 = Round::factory()->closed()->create(['year' => 2026, 'quarter' => 1]);
    $q2 = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    $q3 = Round::factory()->create(['year' => 2026, 'quarter' => 3]);
    Assessment::factory()->forRound($q4)->withScores(['availability' => 50, 'consistency' => 50, 'validity' => 50])->create(['facility_id' => $facility->id]);
    Assessment::factory()->forRound($q1)->withScores(['availability' => 90, 'consistency' => 80, 'validity' => [70, 70, null]])->create(['facility_id' => $facility->id]);
    Assessment::factory()->forRound($q3)->withScores(['availability' => 10, 'consistency' => 10, 'validity' => 10])->create(['facility_id' => $facility->id]);
    $submission = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($q2)->build();

    $context = contextFor([$submission]);

    expect($context->priorOverallScore($facility->id, $context->round(2026, 2)))->toEqualWithDelta(80.0, 0.0001)
        ->and($context->priorOverallScore($facility->id, $context->round(2025, 4)))->toBeNull();
});

it('ignores scores computed under a draft rule version', function (): void {
    $facility = Facility::factory()->create();
    $before = Round::factory()->closed()->create(['year' => 2026, 'quarter' => 1]);
    $round = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    $draft = ScoringRuleVersion::factory()->create();
    $assessment = Assessment::factory()->forRound($before)->create(['facility_id' => $facility->id]);
    DB::table('assessment_scores')->insert(collect(['availability', 'consistency', 'validity'])->map(fn (string $d): array => [
        'assessment_id' => $assessment->id, 'dimension' => $d, 'month_slot' => 1, 'score' => 60,
        'rule_version_id' => $draft->id, 'computed_at' => now(),
    ])->all());
    $submission = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();

    $context = contextFor([$submission]);

    expect($context->priorOverallScore($facility->id, $context->round(2026, 2)))->toBeNull();
});

// ---- Assessor volume (ASSESSOR_VOLUME)

it("counts an assessor's facilities for the day across the database and the batch", function (): void {
    $round = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    $day = CarbonImmutable::parse('2026-04-14 08:00', 'Africa/Lagos');
    foreach (range(1, 3) as $i) {
        Assessment::factory()->forRound($round)->create(['assessor_name' => ' hauwa  IBRAHIM', 'started_at' => $day->addHours($i)]);
    }
    Assessment::factory()->forRound($round)->create(['assessor_name' => 'Hauwa Ibrahim', 'started_at' => $day->addDay()]);
    Assessment::factory()->forRound($round)->create(['assessor_name' => 'Musa Bello', 'started_at' => $day]);
    $facility = Facility::factory()->create();
    $first = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();
    $again = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();

    $context = contextFor([$first, $again]);
    expect($context->assessorVisitCount('Hauwa Ibrahim', $day))->toBe(3);

    $context->registerAccepted($first);
    $context->registerAccepted($again);

    // The visit the next day and Musa's visit are not Hauwa's on the 14th.
    expect($context->assessorVisitCount('Hauwa Ibrahim', $day))->toBe(4)
        ->and($context->assessorVisitCount('Musa Bello', $day))->toBe(1)
        ->and($context->assessorVisitCount('Nobody', $day))->toBe(0);
});

// ---- Form versions

it('knows the form versions that have a field map, and no others', function (): void {
    $context = contextFor([]);

    expect($context->isKnownFormVersion('2026.1'))->toBeTrue()
        ->and($context->isKnownFormVersion('2019.1'))->toBeFalse()
        ->and($context->isKnownFormVersion(''))->toBeFalse();
});
