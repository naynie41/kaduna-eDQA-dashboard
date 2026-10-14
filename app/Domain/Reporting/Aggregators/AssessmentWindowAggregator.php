<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\AssessmentWindow;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use Illuminate\Support\Facades\DB;

/**
 * The assessment window table (Monitor §9.2.4): for each round in view, its window, the first and
 * last visit (local days) and how many accepted assessments it holds, within the LGA, level and
 * owner filters.
 */
final class AssessmentWindowAggregator
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly RoundCache $cache,
    ) {}

    /** @return list<AssessmentWindow> */
    public function windows(ReportFilter $filter): array
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->series($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey(), function () use ($rounds, $filter): array {
            [$where, $filterBindings] = FacilityScoresSql::filterSql($filter);
            $timezone = config('app.timezone');

            $byRound = collect(DB::select(<<<SQL
                SELECT a.round_id,
                    (min(a.started_at) AT TIME ZONE ?)::date::text AS first_visit,
                    (max(a.started_at) AT TIME ZONE ?)::date::text AS last_visit,
                    count(*) AS assessments
                FROM assessments a
                JOIN facilities f ON f.id = a.facility_id
                WHERE a.status IN ('accepted', 'flagged') AND a.round_id = ANY(?::bigint[]){$where}
                GROUP BY a.round_id
                SQL, [$timezone, $timezone, '{'.implode(',', RoundSelection::ids($rounds)).'}', ...$filterBindings]))->keyBy('round_id');

            return array_map(function ($round) use ($byRound): AssessmentWindow {
                $row = $byRound->get($round->id);

                return new AssessmentWindow(
                    roundId: $round->id,
                    label: (string) __('reporting.round_label', ['quarter' => (string) $round->quarter, 'year' => (string) $round->year]),
                    windowStart: $round->window_start->toDateString(),
                    windowEnd: $round->window_end->toDateString(),
                    firstVisit: $row?->first_visit,
                    lastVisit: $row?->last_visit,
                    assessments: Scores::int($row?->assessments),
                );
            }, $rounds);
        });
    }
}
