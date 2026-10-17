<?php

declare(strict_types=1);

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\FacilityScoresQuery;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\MonthSlotAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\DTOs\FacilitySort;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\Metric;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\DTOs\ScoreValue;
use Illuminate\Support\Facades\DB;
use Tests\Support\RandomReportingDataset;

// The aggregators are the specification; round_aggregates must agree with them, row by row, to
// 1e-9, on random datasets built from fixed seeds (so a failure always reproduces).

const TOLERANCE = 1e-9;

beforeEach(function (): void {
    // The aggregators answer from their own SQL here: they are what the view is checked against.
    config(['edqa.aggregation.read_view' => false]);
});

/** One view row's measures, keyed like the view's columns. */
function viewRow(int $round, string $scope, int $scopeId, string $owner, string $level): ?object
{
    return DB::table('round_aggregates')->where(['round_id' => $round, 'scope_type' => $scope, 'scope_id' => $scopeId, 'owner_type' => $owner, 'level' => $level])->first();
}

function assertNear(mixed $view, ScoreValue|float|null $aggregator, string $what): void
{
    $expected = $aggregator instanceof ScoreValue ? $aggregator->value : $aggregator;

    if ($view === null || $expected === null) {
        expect($view)->toBeNull("{$what}: view has a value the aggregator does not")
            ->and($expected)->toBeNull("{$what}: aggregator has a value the view does not");

        return;
    }

    expect(abs((float) $view - $expected))->toBeLessThanOrEqual(TOLERANCE, "{$what}: view {$view} vs aggregator {$expected}");
}

it('agrees with the aggregators on every row', function (int $seed): void {
    $dataset = RandomReportingDataset::build($seed);
    DB::select('select refresh_round_aggregates()');
    $checked = 0;

    foreach ([[$dataset->q1, 1], [$dataset->q2, 2]] as [$roundId, $quarter]) {
        foreach ([null, Ownership::Public, Ownership::Private] as $owner) {
            foreach ([null, FacilityLevel::Primary, FacilityLevel::Secondary, FacilityLevel::Tertiary] as $level) {
                $filter = new ReportFilter(year: 2026, quarter: $quarter, level: $level, owner: $owner);
                $ownerType = $owner->value ?? 'all';
                $levelType = $level->value ?? 'all';
                $at = "seed {$seed}, round Q{$quarter}, {$ownerType}/{$levelType}";

                // State
                $state = viewRow($roundId, 'state', 0, $ownerType, $levelType);
                $summary = app(StateSummaryAggregator::class)->summary($filter);
                $dims = app(DimensionAggregator::class)->dimensions($filter);
                $months = app(MonthSlotAggregator::class)->series($filter);
                if ($summary->facilitiesVisited === 0) {
                    expect($state)->toBeNull("{$at} state: the view has a row for an empty scope");
                } else {
                    expect($state)->not->toBeNull("{$at} state: missing");
                    expect((int) $state->facility_count)->toBe($summary->facilitiesVisited);
                    assertNear($state->availability, $dims->availability, "{$at} state availability");
                    assertNear($state->consistency, $dims->consistency, "{$at} state consistency");
                    assertNear($state->validity, $dims->validity, "{$at} state validity");
                    assertNear($state->overall, $dims->overall, "{$at} state overall");
                    foreach ([1, 2, 3] as $slot) {
                        assertNear($state->{"m{$slot}"}, $months[$slot - 1]->overall, "{$at} state m{$slot}");
                        // v4: each dimension per month slot, for the Visuals month charts.
                        foreach (['availability', 'consistency', 'validity'] as $dimension) {
                            assertNear($state->{"{$dimension}_m{$slot}"}, $months[$slot - 1]->{$dimension}, "{$at} state {$dimension}_m{$slot}");
                        }
                    }
                    $checked++;
                }

                // LGAs
                foreach (app(LgaTableAggregator::class)->rows($filter) as $lga) {
                    $row = viewRow($roundId, 'lga', $lga->lgaId, $ownerType, $levelType);
                    if ($lga->facilities === 0) {
                        expect($row)->toBeNull("{$at} LGA {$lga->code}: the view has a row for an empty LGA");

                        continue;
                    }
                    expect($row)->not->toBeNull("{$at} LGA {$lga->code}: missing");
                    expect((int) $row->facility_count)->toBe($lga->facilities);
                    foreach (['availability', 'consistency', 'validity', 'm1', 'm2', 'm3', 'overall'] as $measure) {
                        assertNear($row->{$measure}, $lga->{$measure}, "{$at} LGA {$lga->code} {$measure}");
                    }
                    $lgaMonths = app(MonthSlotAggregator::class)->series(new ReportFilter(year: 2026, quarter: $quarter, lga: $lga->code, level: $level, owner: $owner));
                    foreach ([1, 2, 3] as $slot) {
                        foreach (['availability', 'consistency', 'validity'] as $dimension) {
                            assertNear($row->{"{$dimension}_m{$slot}"}, $lgaMonths[$slot - 1]->{$dimension}, "{$at} LGA {$lga->code} {$dimension}_m{$slot}");
                        }
                    }
                    $checked++;
                }

                // Wards: no aggregator shows them; checked against the same facility-score SQL.
                $round = Round::query()->findOrFail($roundId);
                [$cte, $bindings] = app(FacilityScoresSql::class)->cte([$round], $filter);
                $wards = DB::select("{$cte} SELECT ward_id, count(*) AS facility_count, avg(availability) AS availability, avg(consistency) AS consistency,
                    avg(validity) AS validity, avg(m1) AS m1, avg(m2) AS m2, avg(m3) AS m3, avg(overall) AS overall
                    FROM facility_scores GROUP BY ward_id", $bindings);
                foreach ($wards as $ward) {
                    $row = viewRow($roundId, 'ward', (int) $ward->ward_id, $ownerType, $levelType);
                    expect($row)->not->toBeNull("{$at} ward {$ward->ward_id}: missing");
                    expect((int) $row->facility_count)->toBe((int) $ward->facility_count);
                    foreach (['availability', 'consistency', 'validity', 'm1', 'm2', 'm3', 'overall'] as $measure) {
                        assertNear($row->{$measure}, $ward->{$measure} === null ? null : (float) $ward->{$measure}, "{$at} ward {$ward->ward_id} {$measure}");
                    }
                    $checked++;
                }
            }
        }

        // Facilities (the view has them for owner 'all' and level 'all' only)
        $filter = new ReportFilter(year: 2026, quarter: $quarter);
        $pages = [];
        foreach (Metric::cases() as $metric) {
            $pages[$metric->value] = collect(app(FacilityScoresQuery::class)->paginate($filter, FacilitySort::default($metric), perPage: 1000)->rows)->keyBy('facilityId');
        }
        foreach ($pages['overall'] as $id => $facility) {
            $row = viewRow($roundId, 'facility', $id, 'all', 'all');
            expect($row)->not->toBeNull("seed {$seed} Q{$quarter} facility {$facility->code}: missing");
            assertNear($row->overall, $facility->overall, "facility {$facility->code} overall");
            foreach ([1, 2, 3] as $slot) {
                assertNear($row->{"m{$slot}"}, $facility->{"m{$slot}"}, "facility {$facility->code} m{$slot}");
            }
            foreach (['availability', 'consistency', 'validity'] as $dimension) {
                assertNear($row->{$dimension}, $pages[$dimension][$id]->score, "facility {$facility->code} {$dimension}");
            }
            $checked++;
        }
    }

    // Every view row was matched: none the aggregators do not know about.
    expect(DB::table('round_aggregates')->count())->toBe($checked)
        ->and($checked)->toBeGreaterThan(100);
})->with([11, 2024, 90210]);

it('leaves out an assessment that is not accepted', function (): void {
    $dataset = RandomReportingDataset::build(7);
    DB::select('select refresh_round_aggregates()');

    $withdrawn = DB::table('assessments')->where('status', 'withdrawn')->value('facility_id');

    expect(viewRow($dataset->q2, 'facility', (int) $withdrawn, 'all', 'all'))->toBeNull();
});
