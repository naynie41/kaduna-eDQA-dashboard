<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\MonthSlotPoint;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use Illuminate\Support\Facades\DB;

/**
 * The Visuals grouped bars (§9.2.5): for each quarter in view and each month slot, the three
 * dimensions and the overall (mean of the three that month), unweighted over facilities. Labelled
 * with the real month: slot 1 of Q2 is April. The Overall / Public / Private toggle is the owner
 * filter.
 */
final class MonthSlotAggregator
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundCache $cache,
    ) {}

    /** @return list<MonthSlotPoint> rounds in time order, slots 1–3 within each */
    public function series(ReportFilter $filter): array
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->series($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey(), function () use ($rounds, $filter): array {
            [$cte, $bindings] = $this->sql->cte($rounds, $filter, perRound: true);

            $columns = [];
            foreach ([1, 2, 3] as $slot) {
                $columns[] = "avg(availability_m{$slot}) AS availability_m{$slot}, avg(consistency_m{$slot}) AS consistency_m{$slot}, "
                    ."avg(validity_m{$slot}) AS validity_m{$slot}, avg(m{$slot}) AS overall_m{$slot}";
            }
            $select = implode(",\n                    ", $columns);

            $byRound = collect(DB::select(<<<SQL
                {$cte}
                SELECT round_id,
                    {$select}
                FROM facility_scores
                GROUP BY round_id
                SQL, $bindings))->keyBy('round_id');

            $points = [];
            foreach ($rounds as $round) {
                $rules = $this->sql->configFor($round);
                $row = $byRound->get($round->id);
                foreach ([1, 2, 3] as $slot) {
                    $month = 3 * ($round->quarter - 1) + $slot;
                    $points[] = new MonthSlotPoint(
                        roundId: $round->id,
                        year: $round->year,
                        quarter: $round->quarter,
                        slot: $slot,
                        month: $month,
                        label: (string) __('reporting.month_label', ['month' => (string) __("reporting.months.{$month}"), 'year' => (string) $round->year]),
                        availability: Scores::of($row?->{"availability_m{$slot}"}, $rules),
                        consistency: Scores::of($row?->{"consistency_m{$slot}"}, $rules),
                        validity: Scores::of($row?->{"validity_m{$slot}"}, $rules),
                        overall: Scores::of($row?->{"overall_m{$slot}"}, $rules),
                    );
                }
            }

            return $points;
        });
    }
}
