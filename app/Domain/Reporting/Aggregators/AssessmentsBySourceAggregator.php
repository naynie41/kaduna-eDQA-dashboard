<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\DTOs\SourceCount;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use Illuminate\Support\Facades\DB;

/**
 * "Assessments by source" (Monitor §9.2.4): the selection's accepted and flagged assessments per
 * source, most first, in the rounds the summaries cover and within the LGA, level and owner
 * filters. It reads assessments.source_id (taken from the submission by the database), never
 * submissions or the source registry: the page names the sources.
 */
final class AssessmentsBySourceAggregator
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly RoundCache $cache,
    ) {}

    /** @return list<SourceCount> */
    public function counts(ReportFilter $filter): array
    {
        $ids = RoundSelection::ids($this->rounds->current($filter));
        if ($ids === []) {
            return [];
        }

        return $this->cache->remember($ids, self::class.':'.$filter->cacheKey(), function () use ($ids, $filter): array {
            [$where, $filterBindings] = FacilityScoresSql::filterSql($filter);

            $rows = DB::select(<<<SQL
                SELECT a.source_id, count(*) AS assessments
                FROM assessments a
                JOIN facilities f ON f.id = a.facility_id
                WHERE a.status IN ('accepted', 'flagged') AND a.round_id = ANY(?::bigint[]){$where}
                GROUP BY a.source_id
                ORDER BY assessments DESC, a.source_id
                SQL, ['{'.implode(',', $ids).'}', ...$filterBindings]);

            return array_values(array_map(fn (object $row): SourceCount => new SourceCount(Scores::int($row->source_id), Scores::int($row->assessments)), $rows));
        });
    }
}
