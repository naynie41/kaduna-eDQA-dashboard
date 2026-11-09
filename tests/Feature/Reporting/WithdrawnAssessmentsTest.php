<?php

declare(strict_types=1);

use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use Illuminate\Support\Facades\DB;
use Tests\Support\ParsedSubmissionBuilder;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// A withdrawn assessment (D-62, Phase 5 step 2: its submission was deleted at source and an
// administrator withdrew it) counts nowhere: no aggregator and no row of round_aggregates reads
// it, and the facility can be assessed again in that round.

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    // F5 (Bravo, 52 overall) in the latest round, the one the aggregators show by default.
    $this->f5 = (int) DB::table('assessments')->where('facility_id', $this->f->facilities['F5'])->where('round_id', $this->f->q2)->value('id');
});

it('leaves a withdrawn assessment out of every aggregator', function (): void {
    DB::table('assessments')->where('id', $this->f5)->update(['status' => 'withdrawn']);

    $summary = app(StateSummaryAggregator::class)->summary(ReportFilter::fromArray([], ReportAudience::Admin));
    expect($summary->facilitiesVisited)->toBe(6)
        // (80 + 70 + 86.6667 + 100 + 88 + 74) / 6: F5's 52 is gone.
        ->and($summary->overall->value)->toEqualWithDelta((550.666667 - 52) / 6, 0.0001)
        ->and($summary->facilitiesWeak)->toBe(0);

    $bravo = collect(app(LgaTableAggregator::class)->rows(ReportFilter::fromArray([], ReportAudience::Admin)))->firstWhere('name', 'Bravo');
    expect($bravo->facilities ?? $bravo['facilities'] ?? null)->toBe(1);
});

it('leaves a withdrawn assessment out of round_aggregates', function (): void {
    DB::select('select refresh_round_aggregates()');
    $before = (int) DB::table('round_aggregates')->where(['round_id' => $this->f->q2, 'scope_type' => 'state', 'owner_type' => 'all', 'level' => 'all'])->value('facility_count');

    DB::table('assessments')->where('id', $this->f5)->update(['status' => 'withdrawn']);
    DB::select('select refresh_round_aggregates()');

    $after = (int) DB::table('round_aggregates')->where(['round_id' => $this->f->q2, 'scope_type' => 'state', 'owner_type' => 'all', 'level' => 'all'])->value('facility_count');
    expect($after)->toBe($before - 1);
});

it('lets the facility be assessed again in the round once its assessment is withdrawn', function (): void {
    $facility = $this->f->facilities['F5'];
    DB::table('assessments')->where('id', $this->f5)->update(['status' => 'withdrawn']);

    // The partial unique index ignores withdrawn rows: a new accepted assessment fits beside it…
    Rows::assessment(['round_id' => $this->f->q2, 'facility_id' => $facility]);
    expect(DB::table('assessments')->where(['round_id' => $this->f->q2, 'facility_id' => $facility])->count())->toBe(2);

    // …but never a second live one.
    expectUniqueViolation(fn () => Rows::assessment(['round_id' => $this->f->q2, 'facility_id' => $facility]), 'assessments_round_facility_live_unique');
});

it('accepts only the assessment statuses it knows', function (): void {
    expectCheckViolation(fn () => DB::table('assessments')->where('id', $this->f5)->update(['status' => 'deleted']), 'assessments_status_valid');
});

it('accepts a new submission for a withdrawn facility and round through the pipeline, with no DUPLICATE_ASSESSMENT', function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $round = Round::factory()->create(['year' => 2027, 'quarter' => 1, 'window_start' => '2027-01-01', 'window_end' => '2027-03-31']);
    $facility = Facility::factory()->create();
    $first = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();
    app(ProcessParsedBatch::class)->execute([[submissionRow($first), $first]]);
    DB::table('assessments')->where('facility_id', $facility->id)->where('round_id', $round->id)->update(['status' => 'withdrawn']);

    $again = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();
    app(ProcessParsedBatch::class)->execute([[submissionRow($again), $again]]);

    expect(DB::table('assessments')->where('facility_id', $facility->id)->where('round_id', $round->id)->orderBy('id')->pluck('status')->all())->toBe(['withdrawn', 'accepted'])
        ->and(DB::table('quarantined_records')->where('instance_id', $again->instanceId)->exists())->toBeFalse();
});
