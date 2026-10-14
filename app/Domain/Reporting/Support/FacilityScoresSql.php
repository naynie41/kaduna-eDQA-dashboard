<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;

/**
 * The CTE every aggregator starts from (ARCHITECTURE.md §7 Aggregation):
 *
 *   facility_scores — one row per facility (per round with $perRound): the mean of its accepted
 *   assessments in the rounds given, each round read under its own rule version (D-32, through
 *   RuleVersionForRound). Columns: [round_id,] facility_id, lga_id, ward_id, ownership, level,
 *   availability, consistency, validity, overall, m1..m3 (month = mean of the three dimensions
 *   in that slot), {dimension}_m1..m3, assessments, first_visit, last_visit, last_round_id.
 *
 * A dimension is the mean of its non-null slots; overall is the mean of the three dimensions,
 * null when one is all N/A, and averages skip nulls, as in round_aggregates.
 */
final class FacilityScoresSql
{
    public const ACCEPTED = ['accepted', 'flagged'];

    public function __construct(
        private readonly RuleVersionForRound $versions,
    ) {}

    /**
     * @param  list<Round>  $rounds
     * @return array{0: string, 1: list<mixed>} SQL starting "WITH …", ready for a final SELECT
     */
    public function cte(array $rounds, ReportFilter $filter, bool $perRound = false): array
    {
        [$roundVersions, $bindings] = $this->roundVersions($rounds);
        [$where, $filterBindings] = self::filterSql($filter);

        // {dimension}_m{slot}: per assessment, then the mean across a facility's assessments.
        $perAssessment = [];
        $perFacility = [];
        foreach (['availability', 'consistency', 'validity'] as $dimension) {
            foreach ([1, 2, 3] as $slot) {
                $perAssessment[] = "avg(s.score) FILTER (WHERE s.dimension = '{$dimension}' AND s.month_slot = {$slot}) AS {$dimension}_m{$slot}";
                $perFacility[] = "avg(x.{$dimension}_m{$slot}) AS {$dimension}_m{$slot}";
            }
        }
        $slotColumns = implode(",\n                    ", $perAssessment);
        $slotMeans = implode(",\n                    ", $perFacility);
        $round = $perRound ? 'x.round_id, ' : '';

        $sql = <<<SQL
            WITH round_versions (round_id, rule_version_id) AS ({$roundVersions}),
            assessment_scores_in_view AS (
                SELECT a.id AS assessment_id, a.facility_id, a.round_id, a.started_at,
                    avg(s.score) FILTER (WHERE s.dimension = 'availability') AS availability,
                    avg(s.score) FILTER (WHERE s.dimension = 'consistency') AS consistency,
                    avg(s.score) FILTER (WHERE s.dimension = 'validity') AS validity,
                    avg(s.score) FILTER (WHERE s.month_slot = 1) AS m1,
                    avg(s.score) FILTER (WHERE s.month_slot = 2) AS m2,
                    avg(s.score) FILTER (WHERE s.month_slot = 3) AS m3,
                    {$slotColumns}
                FROM assessments a
                JOIN round_versions rv ON rv.round_id = a.round_id
                JOIN assessment_scores s ON s.assessment_id = a.id AND s.rule_version_id = rv.rule_version_id
                JOIN facilities f ON f.id = a.facility_id
                WHERE a.status IN ('accepted', 'flagged'){$where}
                GROUP BY a.id, a.facility_id, a.round_id, a.started_at
            ),
            facility_scores AS (
                SELECT {$round}x.facility_id, f.lga_id, f.ward_id, f.ownership, f.level,
                    avg(x.availability) AS availability,
                    avg(x.consistency) AS consistency,
                    avg(x.validity) AS validity,
                    avg((x.availability + x.consistency + x.validity) / 3) AS overall,
                    avg(x.m1) AS m1, avg(x.m2) AS m2, avg(x.m3) AS m3,
                    {$slotMeans},
                    count(*) AS assessments,
                    min(x.started_at) AS first_visit,
                    max(x.started_at) AS last_visit,
                    max(x.round_id) AS last_round_id
                FROM assessment_scores_in_view x
                JOIN facilities f ON f.id = x.facility_id
                GROUP BY {$round}x.facility_id, f.lga_id, f.ward_id, f.ownership, f.level
            )
            SQL;

        return [$sql, [...$bindings, ...$filterBindings]];
    }

    /**
     * The LGA, level and owner filters on facilities f, as an AND clause.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function filterSql(ReportFilter $filter, string $alias = 'f'): array
    {
        $where = '';
        $bindings = [];
        if ($filter->lga !== null) {
            $where .= " AND {$alias}.lga_id = (SELECT id FROM lgas WHERE code = ?)";
            $bindings[] = $filter->lga;
        }
        if ($filter->level !== null) {
            $where .= " AND {$alias}.level = ?";
            $bindings[] = $filter->level->value;
        }
        if ($filter->owner !== null) {
            $where .= " AND {$alias}.ownership = ?";
            $bindings[] = $filter->owner->value;
        }

        return [$where, $bindings];
    }

    /**
     * The rule config that bands a result spanning these rounds: the latest round's (rounds are
     * in time order). Null when no round has a version to be read under.
     *
     * @param  list<Round>  $rounds
     */
    public function config(array $rounds): ?RuleConfig
    {
        $latest = end($rounds);

        return $latest === false ? null : $this->configFor($latest);
    }

    public function configFor(Round $round): ?RuleConfig
    {
        try {
            return $this->versions->config($round);
        } catch (NoPublishedRuleVersion) {
            return null;
        }
    }

    /**
     * (round_id, rule_version_id) for each round; a round with no version to read is left out.
     *
     * @param  list<Round>  $rounds
     * @return array{0: string, 1: list<int>}
     */
    private function roundVersions(array $rounds): array
    {
        $values = [];
        $bindings = [];
        foreach ($rounds as $round) {
            try {
                $versionId = $this->versions->id($round);
            } catch (NoPublishedRuleVersion) {
                continue;
            }
            $values[] = '(?::bigint, ?::bigint)';
            $bindings[] = $round->id;
            $bindings[] = $versionId;
        }

        return $values === []
            ? ['SELECT NULL::bigint, NULL::bigint WHERE false', []]
            : ['VALUES '.implode(', ', $values), $bindings];
    }
}
