<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Each facility's overall score in every round it was assessed in, for SCORE_JUMP's "previous
 * round" (ValidationContextFactory). An aggregate, so it lives in Reporting (hard rule 5).
 *
 * Each round is read under its own rule version (D-32: pinned if closed, current if open);
 * dimension = mean of its non-null month slots, overall = mean of the three dimensions, as
 * round_aggregates computes it. Rounds where a dimension is all N/A (overall null) are left out.
 * One query, whatever the number of facilities.
 */
final class FacilityRoundOverallsQuery
{
    /**
     * @param  list<int>  $facilityIds
     * @return list<array{facility_id: int, round_id: int, overall: float}>
     */
    public function forFacilities(array $facilityIds): array
    {
        $rows = DB::select(<<<'SQL'
            WITH current_rule_version AS (
                SELECT id FROM scoring_rule_versions
                WHERE published_at IS NOT NULL
                ORDER BY version DESC
                LIMIT 1
            ),
            round_rule_version AS (
                SELECT r.id AS round_id,
                    CASE WHEN r.status = 'closed' THEN r.rule_version_id ELSE (SELECT id FROM current_rule_version) END AS rule_version_id
                FROM rounds r
            ),
            dimension_scores AS (
                SELECT a.facility_id, a.round_id, s.dimension, avg(s.score) AS score
                FROM assessments a
                JOIN assessment_scores s ON s.assessment_id = a.id
                JOIN round_rule_version rv ON rv.round_id = a.round_id AND rv.rule_version_id = s.rule_version_id
                WHERE a.facility_id = ANY(?::bigint[])
                GROUP BY a.facility_id, a.round_id, s.dimension
            ),
            overalls AS (
                SELECT facility_id, round_id,
                    (avg(score) FILTER (WHERE dimension = 'availability')
                     + avg(score) FILTER (WHERE dimension = 'consistency')
                     + avg(score) FILTER (WHERE dimension = 'validity')) / 3 AS overall
                FROM dimension_scores
                GROUP BY facility_id, round_id
            )
            SELECT facility_id, round_id, overall FROM overalls WHERE overall IS NOT NULL
            SQL, ['{'.implode(',', $facilityIds).'}']);

        return array_map(fn (object $row): array => [
            'facility_id' => (int) $row->facility_id,
            'round_id' => (int) $row->round_id,
            'overall' => (float) $row->overall,
        ], $rows);
    }
}
