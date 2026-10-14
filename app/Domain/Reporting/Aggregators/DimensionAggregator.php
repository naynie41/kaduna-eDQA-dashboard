<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\DimensionScores;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use App\Domain\Scoring\Enums\Dimension;
use Illuminate\Support\Facades\DB;

/**
 * "The three dimensions" (§9.2.1): each an unweighted mean of facility scores, plus the overall
 * and the weakest dimension for the note (ties go to Availability, then Consistency).
 */
final class DimensionAggregator
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundCache $cache,
    ) {}

    public function dimensions(ReportFilter $filter): DimensionScores
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey(), function () use ($rounds, $filter): DimensionScores {
            $rules = $this->sql->config($rounds);
            [$cte, $bindings] = $this->sql->cte($rounds, $filter);

            $row = DB::selectOne(<<<SQL
                {$cte},
                means AS (
                    SELECT avg(availability) AS availability, avg(consistency) AS consistency,
                        avg(validity) AS validity, avg(overall) AS overall
                    FROM facility_scores
                )
                SELECT *,
                    CASE
                        WHEN availability IS NULL THEN NULL
                        WHEN availability <= consistency AND availability <= validity THEN 'availability'
                        WHEN consistency <= validity THEN 'consistency'
                        ELSE 'validity'
                    END AS weakest
                FROM means
                SQL, $bindings);

            return new DimensionScores(
                availability: Scores::of($row?->availability, $rules),
                consistency: Scores::of($row?->consistency, $rules),
                validity: Scores::of($row?->validity, $rules),
                overall: Scores::of($row?->overall, $rules),
                weakest: is_string($row?->weakest) ? Dimension::from($row->weakest) : null,
            );
        });
    }
}
