<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Reporting\DTOs\FacilityPage;
use App\Domain\Reporting\DTOs\FacilityRow;
use App\Domain\Reporting\DTOs\FacilitySort;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use Illuminate\Support\Facades\DB;

/**
 * The facility view (§9.2.2): one row per facility in view, paginated on the server (50 a page),
 * sortable on any column, weakest first by default. The metric switch picks which scores fill the
 * month cells and the score column; nulls sort last; ties fall back to the facility name.
 */
final class FacilityScoresQuery
{
    public const PER_PAGE = 50;

    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundCache $cache,
    ) {}

    public function paginate(ReportFilter $filter, FacilitySort $sort, int $page = 1, int $perPage = self::PER_PAGE): FacilityPage
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);
        $page = max(1, $page);
        $key = self::class.':'.$filter->cacheKey().':'.$sort->cacheKey().":{$page}:{$perPage}";

        return $this->cache->remember(RoundSelection::ids($rounds), $key, function () use ($rounds, $filter, $sort, $page, $perPage): FacilityPage {
            $rules = $this->sql->config($rounds);
            [$cte, $bindings] = $this->sql->cte($rounds, $filter);
            $metric = $sort->metric;
            $order = $this->orderColumn($sort);
            $direction = $sort->direction === 'desc' ? 'DESC' : 'ASC';

            $rows = DB::select(<<<SQL
                {$cte}
                SELECT fs.facility_id, f.code, f.name, l.name AS lga, w.name AS ward, fs.ownership, fs.level,
                    r.year, r.quarter, (fs.last_visit AT TIME ZONE ?)::date::text AS assessed_on,
                    fs.{$metric->monthColumn(1)} AS m1, fs.{$metric->monthColumn(2)} AS m2, fs.{$metric->monthColumn(3)} AS m3,
                    fs.{$metric->scoreColumn()} AS score, fs.overall,
                    count(*) OVER () AS total
                FROM facility_scores fs
                JOIN facilities f ON f.id = fs.facility_id
                JOIN lgas l ON l.id = fs.lga_id
                JOIN wards w ON w.id = fs.ward_id
                LEFT JOIN rounds r ON r.id = fs.last_round_id
                ORDER BY {$order} {$direction} NULLS LAST, f.name ASC
                LIMIT ? OFFSET ?
                SQL, [...$bindings, config('app.timezone'), $perPage, ($page - 1) * $perPage]);

            $total = $rows === [] ? $this->count($cte, $bindings) : Scores::int($rows[0]->total);

            return new FacilityPage(array_map(fn (object $row): FacilityRow => new FacilityRow(
                facilityId: (int) $row->facility_id,
                code: (string) $row->code,
                name: (string) $row->name,
                lga: (string) $row->lga,
                ward: (string) $row->ward,
                ownership: Ownership::from((string) $row->ownership),
                level: FacilityLevel::from((string) $row->level),
                roundLabel: (string) __('reporting.round_label', ['quarter' => (string) $row->quarter, 'year' => (string) $row->year]),
                assessedOn: (string) $row->assessed_on,
                m1: Scores::of($row->m1, $rules),
                m2: Scores::of($row->m2, $rules),
                m3: Scores::of($row->m3, $rules),
                score: Scores::of($row->score, $rules),
                overall: Scores::of($row->overall, $rules),
            ), $rows), $total, $page, $perPage);
        });
    }

    /** A fixed column for each sort key: request text never reaches SQL. */
    private function orderColumn(FacilitySort $sort): string
    {
        return match ($sort->column) {
            'facility' => 'f.name',
            'lga' => 'l.name',
            'ward' => 'w.name',
            'owner' => 'fs.ownership',
            'assessed' => 'fs.last_visit',
            'm1' => 'fs.'.$sort->metric->monthColumn(1),
            'm2' => 'fs.'.$sort->metric->monthColumn(2),
            'm3' => 'fs.'.$sort->metric->monthColumn(3),
            'overall' => 'fs.overall',
            default => 'fs.'.$sort->metric->scoreColumn(),
        };
    }

    /**
     * Only for a page past the end, which returns no rows to read the total from.
     *
     * @param  list<mixed>  $bindings
     */
    private function count(string $cte, array $bindings): int
    {
        return Scores::int(DB::selectOne("{$cte} SELECT count(*) AS total FROM facility_scores", $bindings)?->total);
    }
}
