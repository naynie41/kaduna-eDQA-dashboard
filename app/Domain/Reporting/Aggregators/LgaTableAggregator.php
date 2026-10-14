<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\LgaRow;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use Illuminate\Support\Facades\DB;

/**
 * The LGA view (§9.2.3): every LGA (only the filtered one if set), by name, with its facility
 * count, three dimensions, three month slots and overall, each an unweighted mean of facility
 * scores. Driven from the lgas table, so it can never list more than the 23 (hard rule 9); an
 * LGA with no visits shows no data.
 */
final class LgaTableAggregator
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundCache $cache,
    ) {}

    /** @return list<LgaRow> */
    public function rows(ReportFilter $filter): array
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey(), function () use ($rounds, $filter): array {
            $rules = $this->sql->config($rounds);
            [$cte, $bindings] = $this->sql->cte($rounds, $filter);
            $lgaFilter = $filter->lga === null ? '' : 'WHERE l.code = ?';

            $rows = DB::select(<<<SQL
                {$cte}
                SELECT l.id, l.code, l.name, count(fs.facility_id) AS facilities,
                    avg(fs.availability) AS availability, avg(fs.consistency) AS consistency,
                    avg(fs.validity) AS validity, avg(fs.m1) AS m1, avg(fs.m2) AS m2, avg(fs.m3) AS m3,
                    avg(fs.overall) AS overall
                FROM lgas l
                LEFT JOIN facility_scores fs ON fs.lga_id = l.id
                {$lgaFilter}
                GROUP BY l.id, l.code, l.name
                ORDER BY l.name
                SQL, $filter->lga === null ? $bindings : [...$bindings, $filter->lga]);

            return array_map(fn (object $row): LgaRow => new LgaRow(
                lgaId: (int) $row->id,
                code: (string) $row->code,
                name: (string) $row->name,
                facilities: Scores::int($row->facilities),
                availability: Scores::of($row->availability, $rules),
                consistency: Scores::of($row->consistency, $rules),
                validity: Scores::of($row->validity, $rules),
                m1: Scores::of($row->m1, $rules),
                m2: Scores::of($row->m2, $rules),
                m3: Scores::of($row->m3, $rules),
                overall: Scores::of($row->overall, $rules),
            ), $rows);
        });
    }
}
