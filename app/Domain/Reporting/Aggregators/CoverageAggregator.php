<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Reporting\DTOs\Coverage;
use App\Domain\Reporting\DTOs\LevelCoverage;
use App\Domain\Reporting\DTOs\LgaCount;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundAggregatesView;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use App\Domain\Round\Models\Round;
use Illuminate\Support\Facades\DB;

/**
 * Monitor coverage (§9.2.4): facilities visited per LGA, most first (every LGA in scope, so the
 * list stays within the 23), the state-mean marker (mean facilities per LGA listed), and
 * facilities and overall by level. From round_aggregates for one round, from SQL otherwise.
 */
final class CoverageAggregator
{
    private const LEVEL_ORDER = "array_position(ARRAY['primary', 'secondary', 'tertiary']::varchar[], level::varchar)";

    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundAggregatesView $view,
        private readonly RoundCache $cache,
    ) {}

    public function coverage(ReportFilter $filter): Coverage
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey(), function () use ($rounds, $filter): Coverage {
            $rules = $this->sql->config($rounds);
            [$perLga, $byLevel] = $this->view->covers($rounds) ? $this->fromView($rounds[0], $filter) : $this->fromSql($rounds, $filter);

            return new Coverage(
                perLga: array_map(fn (object $row): LgaCount => new LgaCount((int) $row->id, (string) $row->code, (string) $row->name, Scores::int($row->facilities)), $perLga),
                stateMean: $perLga === [] ? 0.0 : (float) $perLga[0]->state_mean,
                byLevel: array_map(fn (object $row): LevelCoverage => new LevelCoverage(
                    FacilityLevel::from((string) $row->level),
                    Scores::int($row->facilities),
                    Scores::of($row->overall, $rules),
                ), $byLevel),
            );
        });
    }

    /** @return array{0: list<object>, 1: list<object>} per LGA, by level */
    private function fromView(Round $round, ReportFilter $filter): array
    {
        $owner = RoundAggregatesView::ownerType($filter);
        $lgaFilter = $filter->lga === null ? '' : 'WHERE l.code = ?';
        [$scope, $scopeId, $scopeBindings] = RoundAggregatesView::scope($filter);
        $levels = $filter->level === null ? "level <> 'all'" : 'level = ?';
        $order = self::LEVEL_ORDER;

        $perLga = DB::select(<<<SQL
            WITH per_lga AS (
                SELECT l.id, l.code, l.name, coalesce(ra.facility_count, 0) AS facilities
                FROM lgas l
                LEFT JOIN round_aggregates ra ON ra.scope_type = 'lga' AND ra.scope_id = l.id
                    AND ra.round_id = ? AND ra.owner_type = ? AND ra.level = ?
                {$lgaFilter}
            )
            SELECT *, avg(facilities) OVER () AS state_mean
            FROM per_lga
            ORDER BY facilities DESC, name
            SQL, [$round->id, $owner, RoundAggregatesView::level($filter), ...($filter->lga === null ? [] : [$filter->lga])]);

        $byLevel = DB::select(<<<SQL
            SELECT level, facility_count AS facilities, overall
            FROM round_aggregates
            WHERE round_id = ? AND scope_type = ? AND scope_id = {$scopeId} AND owner_type = ? AND {$levels}
            ORDER BY {$order}
            SQL, [$round->id, $scope, ...$scopeBindings, $owner, ...($filter->level === null ? [] : [$filter->level->value])]);

        return [$perLga, $byLevel];
    }

    /**
     * @param  list<Round>  $rounds
     * @return array{0: list<object>, 1: list<object>} per LGA, by level
     */
    private function fromSql(array $rounds, ReportFilter $filter): array
    {
        [$cte, $bindings] = $this->sql->cte($rounds, $filter);
        $lgaFilter = $filter->lga === null ? '' : 'WHERE l.code = ?';
        $order = self::LEVEL_ORDER;

        $perLga = DB::select(<<<SQL
            {$cte},
            per_lga AS (
                SELECT l.id, l.code, l.name, count(fs.facility_id) AS facilities
                FROM lgas l
                LEFT JOIN facility_scores fs ON fs.lga_id = l.id
                {$lgaFilter}
                GROUP BY l.id, l.code, l.name
            )
            SELECT *, avg(facilities) OVER () AS state_mean
            FROM per_lga
            ORDER BY facilities DESC, name
            SQL, $filter->lga === null ? $bindings : [...$bindings, $filter->lga]);

        $byLevel = DB::select(<<<SQL
            {$cte}
            SELECT level, count(*) AS facilities, avg(overall) AS overall
            FROM facility_scores
            GROUP BY level
            ORDER BY {$order}
            SQL, $bindings);

        return [$perLga, $byLevel];
    }
}
