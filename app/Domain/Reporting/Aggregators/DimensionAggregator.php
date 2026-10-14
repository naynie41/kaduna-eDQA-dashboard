<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\DimensionScores;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundAggregatesView;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Enums\Dimension;
use Illuminate\Support\Facades\DB;

/**
 * "The three dimensions" (§9.2.1): each an unweighted mean of facility scores, plus the overall
 * and the weakest dimension for the note (ties go to Availability, then Consistency). From
 * round_aggregates for one round, from SQL otherwise.
 */
final class DimensionAggregator
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundAggregatesView $view,
        private readonly RoundCache $cache,
    ) {}

    public function dimensions(ReportFilter $filter): DimensionScores
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey(), function () use ($rounds, $filter): DimensionScores {
            $rules = $this->sql->config($rounds);
            $row = $this->view->covers($rounds) ? $this->fromView($rounds[0], $filter) : $this->fromSql($rounds, $filter);

            return self::map($row, $rules);
        });
    }

    private function fromView(Round $round, ReportFilter $filter): ?object
    {
        [$scope, $scopeId, $scopeBindings] = RoundAggregatesView::scope($filter);
        $weakest = RoundAggregatesView::WEAKEST;

        return DB::selectOne(<<<SQL
            SELECT availability, consistency, validity, overall, {$weakest} AS weakest
            FROM round_aggregates
            WHERE round_id = ? AND scope_type = ? AND scope_id = {$scopeId} AND owner_type = ? AND level = ?
            SQL, [$round->id, $scope, ...$scopeBindings, RoundAggregatesView::ownerType($filter), RoundAggregatesView::level($filter)]);
    }

    /** @param  list<Round>  $rounds */
    private function fromSql(array $rounds, ReportFilter $filter): ?object
    {
        [$cte, $bindings] = $this->sql->cte($rounds, $filter);
        $weakest = RoundAggregatesView::WEAKEST;

        return DB::selectOne(<<<SQL
            {$cte},
            means AS (
                SELECT avg(availability) AS availability, avg(consistency) AS consistency,
                    avg(validity) AS validity, avg(overall) AS overall
                FROM facility_scores
            )
            SELECT *, {$weakest} AS weakest
            FROM means
            SQL, $bindings);
    }

    private static function map(?object $row, ?RuleConfig $rules): DimensionScores
    {
        return new DimensionScores(
            availability: Scores::of($row?->availability, $rules),
            consistency: Scores::of($row?->consistency, $rules),
            validity: Scores::of($row?->validity, $rules),
            overall: Scores::of($row?->overall, $rules),
            weakest: is_string($row?->weakest) ? Dimension::from($row->weakest) : null,
        );
    }
}
