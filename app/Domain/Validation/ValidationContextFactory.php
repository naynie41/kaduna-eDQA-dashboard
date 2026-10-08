<?php

declare(strict_types=1);

namespace App\Domain\Validation;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Validation\DTOs\FacilitySnapshot;
use App\Domain\Validation\DTOs\LgaSnapshot;
use App\Domain\Validation\DTOs\RoundSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the ValidationContext for one batch (one ODK page, up to 500 submissions) in a fixed
 * number of queries, whatever the batch size: no query per submission or per rule.
 */
final class ValidationContextFactory
{
    /** LGAs, facilities, rounds, accepted assessments, prior scores, assessor-days. */
    public const QUERY_COUNT = 6;

    /** @param  list<ParsedSubmission>  $submissions */
    public function forBatch(array $submissions): ValidationContext
    {
        $facilities = $this->facilities($submissions);
        $facilityIds = array_values(array_map(fn (FacilitySnapshot $f): int => $f->id, $facilities));
        $rounds = $this->rounds();

        return new ValidationContext(
            lgas: $this->lgas(),
            facilities: $facilities,
            rounds: $rounds,
            acceptedInstances: $this->acceptedInstances($facilityIds),
            priorScores: $this->priorScores($facilityIds, $rounds),
            assessorDays: $this->assessorDays($submissions),
            knownFormVersions: $this->knownFormVersions(),
        );
    }

    /** @return array<string, LgaSnapshot> */
    private function lgas(): array
    {
        $lgas = [];
        foreach (DB::table('lgas')->get(['id', 'code', 'name']) as $row) {
            $lga = new LgaSnapshot((int) $row->id, (string) $row->code, (string) $row->name);
            $lgas['code:'.ValidationContext::normaliseLga($lga->code)] = $lga;
            $lgas['name:'.ValidationContext::normaliseLga($lga->name)] = $lga;
        }

        return $lgas;
    }

    /**
     * Only the facilities the batch refers to, active or not.
     *
     * @param  list<ParsedSubmission>  $submissions
     * @return array<string, FacilitySnapshot>
     */
    private function facilities(array $submissions): array
    {
        $codes = [];
        foreach ($submissions as $submission) {
            $code = trim((string) $submission->facilityRef);
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        $facilities = [];
        $rows = DB::table('facilities')->whereIn('code', array_keys($codes))->get(['id', 'code', 'lga_id', 'is_active']);
        foreach ($rows as $row) {
            $facilities[(string) $row->code] = new FacilitySnapshot((int) $row->id, (string) $row->code, (int) $row->lga_id, (bool) $row->is_active);
        }

        return $facilities;
    }

    /** @return list<RoundSnapshot> */
    private function rounds(): array
    {
        $rounds = [];
        foreach (DB::table('rounds')->orderBy('year')->orderBy('quarter')->get() as $row) {
            $rounds[] = new RoundSnapshot(
                (int) $row->id,
                (int) $row->year,
                (int) $row->quarter,
                RoundStatus::from((string) $row->status),
                CarbonImmutable::parse((string) $row->window_start, (string) config('app.timezone')),
                CarbonImmutable::parse((string) $row->window_end, (string) config('app.timezone')),
            );
        }

        return $rounds;
    }

    /**
     * Every row in assessments is accepted: assessments are only created from submissions that
     * passed the hard rules (round_aggregates.sql, assumption A1).
     *
     * @param  list<int>  $facilityIds
     * @return array<string, list<string>>
     */
    private function acceptedInstances(array $facilityIds): array
    {
        $accepted = [];
        $rows = DB::table('assessments')
            ->join('submissions', 'submissions.id', '=', 'assessments.submission_id')
            ->whereIn('assessments.facility_id', $facilityIds)
            ->get(['assessments.round_id', 'assessments.facility_id', 'submissions.instance_id']);

        foreach ($rows as $row) {
            $accepted["{$row->round_id}:{$row->facility_id}"][] = (string) $row->instance_id;
        }

        return $accepted;
    }

    /**
     * Overall score per facility per round under the highest published rule version, computed
     * as round_aggregates does: dimension = mean of its non-null month slots, overall = mean of
     * the three dimensions (null if any dimension is all-N/A, and then skipped).
     *
     * @param  list<int>  $facilityIds
     * @param  list<RoundSnapshot>  $rounds
     * @return array<int, list<array{sequence: int, overall: float}>>
     */
    private function priorScores(array $facilityIds, array $rounds): array
    {
        $rows = DB::select(<<<'SQL'
            WITH current_rule_version AS (
                SELECT id FROM scoring_rule_versions
                WHERE published_at IS NOT NULL
                ORDER BY version DESC
                LIMIT 1
            ),
            dimension_scores AS (
                SELECT a.facility_id, a.round_id, s.dimension, avg(s.score) AS score
                FROM assessments a
                JOIN assessment_scores s ON s.assessment_id = a.id
                JOIN current_rule_version v ON v.id = s.rule_version_id
                WHERE a.facility_id = ANY(?::bigint[])
                GROUP BY a.facility_id, a.round_id, s.dimension
            )
            SELECT facility_id, round_id,
                (avg(score) FILTER (WHERE dimension = 'availability')
                 + avg(score) FILTER (WHERE dimension = 'consistency')
                 + avg(score) FILTER (WHERE dimension = 'validity')) / 3 AS overall
            FROM dimension_scores
            GROUP BY facility_id, round_id
            SQL, ['{'.implode(',', $facilityIds).'}']);

        $sequence = [];
        foreach ($rounds as $round) {
            $sequence[$round->id] = $round->sequence();
        }

        $scores = [];
        foreach ($rows as $row) {
            if ($row->overall !== null && isset($sequence[(int) $row->round_id])) {
                $scores[(int) $row->facility_id][] = ['sequence' => $sequence[(int) $row->round_id], 'overall' => (float) $row->overall];
            }
        }

        return array_map(function (array $list): array {
            usort($list, fn (array $a, array $b): int => $b['sequence'] <=> $a['sequence']);

            return $list;
        }, $scores);
    }

    /**
     * Distinct facilities per assessor per local day, for the days the batch's visits fall on.
     *
     * @param  list<ParsedSubmission>  $submissions
     * @return array<string, array<int, true>>
     */
    private function assessorDays(array $submissions): array
    {
        $dates = [];
        foreach ($submissions as $submission) {
            $day = $submission->visitDate();
            if ($day !== null) {
                $dates[$day->toDateString()] = true;
            }
        }

        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT assessor_name, (started_at AT TIME ZONE ?)::date::text AS day, facility_id
            FROM assessments
            WHERE (started_at AT TIME ZONE ?)::date = ANY(?::date[])
            SQL, [config('app.timezone'), config('app.timezone'), '{'.implode(',', array_keys($dates)).'}']);

        $days = [];
        foreach ($rows as $row) {
            $days[ValidationContext::assessorDayKey((string) $row->assessor_name, (string) $row->day)][(int) $row->facility_id] = true;
        }

        return $days;
    }

    /** @return array<string, true> */
    private function knownFormVersions(): array
    {
        $versions = config('edqa_field_maps.versions');

        return array_fill_keys(array_map(strval(...), array_keys(is_array($versions) ? $versions : [])), true);
    }
}
