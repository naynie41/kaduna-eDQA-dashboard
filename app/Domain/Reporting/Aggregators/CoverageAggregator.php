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
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use Illuminate\Support\Facades\DB;

/**
 * Monitor coverage (§9.2.4): facilities visited per LGA, most first (every LGA in scope, so the
 * list stays within the 23), the state-mean marker (mean facilities per LGA listed), and
 * facilities and overall by level.
 */
final class CoverageAggregator
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundCache $cache,
    ) {}

    public function coverage(ReportFilter $filter): Coverage
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey(), function () use ($rounds, $filter): Coverage {
            $rules = $this->sql->config($rounds);
            [$cte, $bindings] = $this->sql->cte($rounds, $filter);
            $lgaFilter = $filter->lga === null ? '' : 'WHERE l.code = ?';

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
                ORDER BY array_position(ARRAY['primary', 'secondary', 'tertiary']::varchar[], level::varchar)
                SQL, $bindings);

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
}
