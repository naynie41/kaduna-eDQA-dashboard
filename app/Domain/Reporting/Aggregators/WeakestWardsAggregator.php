<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\DTOs\WardScore;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use Illuminate\Support\Facades\DB;

/**
 * "Weakest wards in {LGA}" (Home §9.2.1 with an LGA chosen): the lowest wards by overall, an
 * unweighted mean of their facilities, six by default, only wards with a score; ties by name.
 * Without an LGA filter it covers the whole state, though Home shows the weakest LGAs then.
 */
final class WeakestWardsAggregator
{
    public const LIMIT = 6;

    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundCache $cache,
    ) {}

    /** @return list<WardScore> */
    public function weakest(ReportFilter $filter, int $limit = self::LIMIT): array
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey().":{$limit}", function () use ($rounds, $filter, $limit): array {
            $rules = $this->sql->config($rounds);
            [$cte, $bindings] = $this->sql->cte($rounds, $filter);

            $rows = DB::select(<<<SQL
                {$cte}
                SELECT w.id, w.name, count(*) AS facilities, avg(fs.overall) AS overall
                FROM facility_scores fs
                JOIN wards w ON w.id = fs.ward_id
                GROUP BY w.id, w.name
                HAVING avg(fs.overall) IS NOT NULL
                ORDER BY overall ASC, w.name
                LIMIT ?
                SQL, [...$bindings, $limit]);

            return array_map(fn (object $row): WardScore => new WardScore(
                wardId: (int) $row->id,
                name: (string) $row->name,
                facilities: Scores::int($row->facilities),
                overall: Scores::of($row->overall, $rules),
            ), $rows);
        });
    }
}
