<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\LgaScore;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use Illuminate\Support\Facades\DB;

/**
 * "Weakest local governments" (Home §9.2.1): the lowest LGAs by overall (an unweighted mean of
 * their facilities), six by default, only LGAs with a score; ties by name.
 */
final class WeakestLgasAggregator
{
    public const LIMIT = 6;

    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundCache $cache,
    ) {}

    /** @return list<LgaScore> */
    public function weakest(ReportFilter $filter, int $limit = self::LIMIT): array
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey().":{$limit}", function () use ($rounds, $filter, $limit): array {
            $rules = $this->sql->config($rounds);
            [$cte, $bindings] = $this->sql->cte($rounds, $filter);

            $rows = DB::select(<<<SQL
                {$cte}
                SELECT l.id, l.code, l.name, count(*) AS facilities, avg(fs.overall) AS overall
                FROM facility_scores fs
                JOIN lgas l ON l.id = fs.lga_id
                GROUP BY l.id, l.code, l.name
                HAVING avg(fs.overall) IS NOT NULL
                ORDER BY overall ASC, l.name
                LIMIT ?
                SQL, [...$bindings, $limit]);

            return array_map(fn (object $row): LgaScore => new LgaScore(
                lgaId: (int) $row->id,
                code: (string) $row->code,
                name: (string) $row->name,
                facilities: Scores::int($row->facilities),
                overall: Scores::of($row->overall, $rules),
            ), $rows);
        });
    }
}
