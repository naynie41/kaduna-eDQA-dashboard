<?php

declare(strict_types=1);

use App\Domain\Reporting\Aggregators\CoverageAggregator;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\Aggregators\WeakestLgasAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// ARCHITECTURE.md §7 Reference figures: these must pass before anything visual.
//   Public  92 / 79 / 92 → 87.67, shown 88
//   Private 75 / 62 / 84 → 73.67, shown 74 half-up, 73 truncated (Q-09: the live tool shows 73)
//   State, 2,000 public + 219 private, unweighted over facilities → ≈ 86.29, shown 86

beforeEach(function (): void {
    // A state-sized dataset must answer in seconds (§10: p95 < 1.5 s per page): fail, don't hang.
    DB::statement("SET statement_timeout = '30s'");
    $this->seed(LgaSeeder::class);
    $version = Rows::ruleVersion(['config' => json_encode(ReportingFixture::CONFIG)]);
    $round = Rows::round(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);
    bulkAssessments($round, $version, 'public', 2000, [92, 79, 92]);
    bulkAssessments($round, $version, 'private', 219, [75, 62, 84]);

    // A live database has statistics for tables this size (autovacuum). These rows sit in the
    // test's own transaction, which autovacuum never sees, so without this the planner works from
    // whatever an earlier test left behind and, under parallel load, can choose a nested-loop plan
    // that takes minutes. ANALYZE inside the transaction counts the rows it inserted.
    DB::statement('ANALYZE facilities, assessments, assessment_scores, submissions');
});

/**
 * Facilities spread over the 23 LGAs, one assessment each, nine identical scores per dimension,
 * inserted in chunks so 2,219 facilities and ~20,000 scores load in a few seconds.
 *
 * @param  array{0: int, 1: int, 2: int}  $dims  availability, consistency, validity
 */
function bulkAssessments(int $round, int $version, string $ownership, int $count, array $dims): void
{
    $lgas = DB::table('lgas')->orderBy('id')->pluck('id')->all();
    $wards = [];
    foreach ($lgas as $lga) {
        $wards[$lga] = DB::table('wards')->where('lga_id', $lga)->value('id') ?? Rows::ward(['lga_id' => $lga]);
    }

    $now = now();
    foreach (array_chunk(range(1, $count), 500) as $chunk) {
        $facilityRows = array_map(function (int $i) use ($lgas, $wards, $ownership, $now): array {
            $lga = $lgas[$i % count($lgas)];

            return ['code' => "REF-{$ownership}-{$i}", 'name' => "Ref {$ownership} {$i}", 'lga_id' => $lga, 'ward_id' => $wards[$lga],
                'level' => 'primary', 'ownership' => $ownership, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
        }, $chunk);
        DB::table('facilities')->insert($facilityRows);
        $facilityIds = DB::table('facilities')->whereIn('code', array_column($facilityRows, 'code'))->pluck('id')->all();

        $submissionRows = array_map(fn (int $id): array => ['source_id' => Rows::sourceId(), 'external_id' => "uuid:ref-{$id}", 'instance_id' => "uuid:ref-{$id}", 'form_ref' => 'dqa', 'form_version' => '2026.1',
            'payload' => '{}', 'submitted_at' => $now, 'received_at' => $now, 'status' => 'accepted'], $facilityIds);
        DB::table('submissions')->insert($submissionRows);
        $submissions = DB::table('submissions')->whereIn('instance_id', array_column($submissionRows, 'instance_id'))->pluck('id', 'instance_id');

        DB::table('assessments')->insert(array_map(fn (int $id): array => ['round_id' => $round, 'facility_id' => $id,
            'submission_id' => $submissions["uuid:ref-{$id}"], 'assessor_name' => 'Ref', 'started_at' => '2026-05-04 09:00:00+01',
            'ended_at' => '2026-05-04 10:00:00+01', 'status' => 'accepted', 'created_at' => $now, 'updated_at' => $now], $facilityIds));
        $assessments = DB::table('assessments')->where('round_id', $round)->whereIn('facility_id', $facilityIds)->pluck('id')->all();

        $scores = [];
        foreach ($assessments as $assessment) {
            foreach (['availability' => $dims[0], 'consistency' => $dims[1], 'validity' => $dims[2]] as $dimension => $score) {
                foreach ([1, 2, 3] as $slot) {
                    $scores[] = ['assessment_id' => $assessment, 'dimension' => $dimension, 'month_slot' => $slot,
                        'score' => $score, 'rule_version_id' => $version, 'computed_at' => $now];
                }
            }
        }
        foreach (array_chunk($scores, 2000) as $part) {
            DB::table('assessment_scores')->insert($part);
        }
    }
}

it('reproduces the public figure: 87.67, shown 88', function (): void {
    $public = app(OwnerSplitAggregator::class)->split(ReportFilter::fromArray([]))->public;

    expect($public->overall->value)->toEqualWithDelta(87.666667, 0.0001)
        ->and($public->overall->display)->toBe('87.7')
        ->and($public->overall->headline)->toBe('88')
        ->and($public->facilities)->toBe(2000);
});

it('reproduces the private figure: 73.67, shown 74 half-up and 73 truncated', function (): void {
    $private = app(OwnerSplitAggregator::class)->split(ReportFilter::fromArray([]))->private;

    expect($private->overall->value)->toEqualWithDelta(73.666667, 0.0001)
        ->and($private->overall->headline)->toBe('74');

    config(['edqa.presentation.rounding' => 'truncate']);
    Cache::flush();

    expect(app(OwnerSplitAggregator::class)->split(ReportFilter::fromArray([]))->private->overall->headline)->toBe('73');
});

it('reproduces the state figure: ≈ 86.29, shown 86, unweighted over 2,219 facilities', function (): void {
    $state = app(StateSummaryAggregator::class)->summary(ReportFilter::fromArray([]));

    expect($state->overall->value)->toEqualWithDelta((2000 * 263 / 3 + 219 * 221 / 3) / 2219, 0.0001)
        // Exactly 86.28496. §7's "≈ 86.29" comes from the rounded group means
        // (2000 × 87.67 + 219 × 73.67) / 2219 = 86.288, hence the wider margin here.
        ->and($state->overall->value)->toEqualWithDelta(86.29, 0.01)
        ->and($state->overall->headline)->toBe('86')
        ->and($state->facilitiesVisited)->toBe(2219)
        ->and($state->lgasCovered)->toBe(23);
});

it('never reports more than 23 LGAs, from any aggregator', function (): void {
    $filter = ReportFilter::fromArray([]);

    expect(count(app(LgaTableAggregator::class)->rows($filter)))->toBe(23)
        ->and(count(app(CoverageAggregator::class)->coverage($filter)->perLga))->toBeLessThanOrEqual(23)
        ->and(count(app(WeakestLgasAggregator::class)->weakest($filter)))->toBeLessThanOrEqual(23)
        ->and(app(StateSummaryAggregator::class)->summary($filter)->lgasCovered)->toBeLessThanOrEqual(23);
});
