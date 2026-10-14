<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\DTOs\TrendPoint;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use Illuminate\Support\Facades\DB;

/**
 * "Quality score by quarter" (§9.2.1): overall, public and private per round, for the year in
 * view (every round with no year; the quarter filter is ignored, a trend needs more than one
 * point). Each round is read and banded under its own rule version (D-32).
 */
final class QuarterTrendAggregator
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundCache $cache,
    ) {}

    /** @return list<TrendPoint> */
    public function trend(ReportFilter $filter): array
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->series($filter, withoutQuarter: true);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey(), function () use ($rounds, $filter): array {
            [$cte, $bindings] = $this->sql->cte($rounds, $filter, perRound: true);

            $byRound = collect(DB::select(<<<SQL
                {$cte}
                SELECT round_id, avg(overall) AS overall,
                    avg(overall) FILTER (WHERE ownership = 'public') AS public,
                    avg(overall) FILTER (WHERE ownership = 'private') AS private
                FROM facility_scores
                GROUP BY round_id
                SQL, $bindings))->keyBy('round_id');

            $points = [];
            foreach ($rounds as $round) {
                $rules = $this->sql->configFor($round);
                $row = $byRound->get($round->id);
                $points[] = new TrendPoint(
                    roundId: $round->id,
                    year: $round->year,
                    quarter: $round->quarter,
                    label: (string) __('reporting.round_label', ['quarter' => (string) $round->quarter, 'year' => (string) $round->year]),
                    overall: Scores::of($row?->overall, $rules),
                    public: Scores::of($row?->public, $rules),
                    private: Scores::of($row?->private, $rules),
                );
            }

            return $points;
        });
    }
}
