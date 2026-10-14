<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Facility\Enums\Ownership;
use App\Domain\Reporting\DTOs\GroupScores;
use App\Domain\Reporting\DTOs\OwnerSplit;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\DTOs\ScoreValue;
use Illuminate\Support\Facades\DB;

/** Public vs Private (§9.2.1, §9.2.4): each an unweighted mean of its facilities. */
final class OwnerSplitAggregator
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundCache $cache,
    ) {}

    public function split(ReportFilter $filter): OwnerSplit
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey(), function () use ($rounds, $filter): OwnerSplit {
            $rules = $this->sql->config($rounds);
            [$cte, $bindings] = $this->sql->cte($rounds, $filter);

            $rows = collect(DB::select(<<<SQL
                {$cte}
                SELECT ownership, count(*) AS facilities, avg(availability) AS availability,
                    avg(consistency) AS consistency, avg(validity) AS validity, avg(overall) AS overall
                FROM facility_scores
                GROUP BY ownership
                SQL, $bindings))->keyBy('ownership');

            return new OwnerSplit(
                public: self::group($rows->get(Ownership::Public->value), $rules),
                private: self::group($rows->get(Ownership::Private->value), $rules),
            );
        });
    }

    private static function group(?object $row, ?RuleConfig $rules): GroupScores
    {
        if ($row === null) {
            return new GroupScores(0, ScoreValue::none(), ScoreValue::none(), ScoreValue::none(), ScoreValue::none());
        }

        return new GroupScores(
            facilities: Scores::int($row->facilities),
            availability: Scores::of($row->availability, $rules),
            consistency: Scores::of($row->consistency, $rules),
            validity: Scores::of($row->validity, $rules),
            overall: Scores::of($row->overall, $rules),
        );
    }
}
