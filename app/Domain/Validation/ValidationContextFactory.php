<?php

declare(strict_types=1);

namespace App\Domain\Validation;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\FieldMap;
use App\Domain\Reporting\Queries\FacilityRoundOverallsQuery;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Validation\DTOs\FacilitySnapshot;
use App\Domain\Validation\DTOs\LgaSnapshot;
use App\Domain\Validation\DTOs\RoundSnapshot;
use App\Support\Text\Normalise;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the ValidationContext for one batch (one ODK page, up to 500 submissions) in a fixed
 * number of queries, whatever the batch size: no query per submission or per rule.
 */
final class ValidationContextFactory
{
    public function __construct(
        private readonly FacilityRoundOverallsQuery $overalls,
    ) {}

    /** LGAs, facilities, rounds, accepted assessments, prior scores, assessor-days, rule config. */
    public const QUERY_COUNT = 7;

    /** @param  list<ParsedSubmission>  $submissions */
    public function forBatch(array $submissions): ValidationContext
    {
        $facilities = $this->facilities($submissions);
        $facilityIds = array_values(array_map(fn (FacilitySnapshot $f): int => $f->id, $facilities));
        $rounds = $this->rounds();
        [$ruleVersionId, $ruleConfig] = $this->ruleVersion();
        [$accepted, $acceptedSources] = $this->acceptedInstances($facilityIds);

        return new ValidationContext(
            lgas: $this->lgas(),
            facilities: $facilities,
            rounds: $rounds,
            acceptedInstances: $accepted,
            acceptedSources: $acceptedSources,
            priorScores: $this->priorScores($facilityIds, $rounds),
            assessorDays: $this->assessorDays($submissions),
            knownFormVersions: $this->knownFormVersions(),
            ruleConfig: $ruleConfig,
            ruleVersionId: $ruleVersionId,
        );
    }

    /** @return array<string, LgaSnapshot> */
    private function lgas(): array
    {
        $lgas = [];
        foreach (DB::table('lgas')->get(['id', 'code', 'name']) as $row) {
            $lga = new LgaSnapshot((int) $row->id, (string) $row->code, (string) $row->name);
            $lgas['code:'.Normalise::matchKey($lga->code)] = $lga;
            $lgas['name:'.Normalise::matchKey($lga->name)] = $lga;
        }

        return $lgas;
    }

    /**
     * Only the facilities the batch refers to, active or not: by master-list code, and by a
     * source's own reference (D-72: facility_source_refs, for that source only). A real code wins
     * over a reference that happens to equal it; a reference two sources in the batch map to
     * different facilities is left out, so it stays unknown.
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

        $bySource = [];
        foreach ($submissions as $submission) {
            $ref = trim((string) $submission->facilityRef);
            if ($ref !== '' && ! isset($facilities[$ref])) {
                $bySource[$submission->source][$ref] = true;
            }
        }
        $mapped = [];
        foreach ($bySource as $source => $refs) {
            $rows = DB::table('facility_source_refs as r')
                ->join('submission_sources as s', 's.id', '=', 'r.source_id')
                ->join('facilities as f', 'f.id', '=', 'r.facility_id')
                ->where('s.key', $source)->whereIn('r.ref', array_keys($refs))
                ->get(['r.ref', 'f.id', 'f.code', 'f.lga_id', 'f.is_active']);
            foreach ($rows as $row) {
                $snapshot = new FacilitySnapshot((int) $row->id, (string) $row->code, (int) $row->lga_id, (bool) $row->is_active);
                $ref = (string) $row->ref;
                if (! array_key_exists($ref, $mapped)) {
                    $mapped[$ref] = $snapshot;
                } elseif ($mapped[$ref] !== null && $mapped[$ref]->id !== $snapshot->id) {
                    $mapped[$ref] = null; // two sources map it to different facilities: ambiguous, stays unknown
                }
            }
        }
        foreach ($mapped as $ref => $snapshot) {
            if ($snapshot !== null) {
                $facilities[$ref] = $snapshot;
            }
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
                $row->rule_version_id === null ? null : (int) $row->rule_version_id,
            );
        }

        return $rounds;
    }

    /**
     * Every row in assessments is accepted: assessments are only created from submissions that
     * passed the hard rules (round_aggregates.sql, assumption A1).
     *
     * Each with the key of the source it came from (§5.8: a duplicate names both sources).
     *
     * @param  list<int>  $facilityIds
     * @return array{0: array<string, list<string>>, 1: array<string, string>} "round:facility" => instance IDs; instance ID => source key
     */
    private function acceptedInstances(array $facilityIds): array
    {
        $accepted = [];
        $sources = [];
        $rows = DB::table('assessments')
            ->join('submissions', 'submissions.id', '=', 'assessments.submission_id')
            ->leftJoin('submission_sources', 'submission_sources.id', '=', 'submissions.source_id')
            ->whereIn('assessments.facility_id', $facilityIds)
            ->get(['assessments.round_id', 'assessments.facility_id', 'submissions.instance_id', 'submission_sources.key as source']);

        foreach ($rows as $row) {
            $accepted["{$row->round_id}:{$row->facility_id}"][] = (string) $row->instance_id;
            if (is_string($row->source)) {
                $sources[(string) $row->instance_id] = $row->source;
            }
        }

        return [$accepted, $sources];
    }

    /**
     * Each facility's overall per round, latest round first, from FacilityRoundOverallsQuery (the
     * aggregate lives in Reporting, hard rule 5). Only rounds the context knows are kept.
     *
     * @param  list<int>  $facilityIds
     * @param  list<RoundSnapshot>  $rounds
     * @return array<int, list<array{sequence: int, overall: float}>>
     */
    private function priorScores(array $facilityIds, array $rounds): array
    {
        $sequence = [];
        foreach ($rounds as $round) {
            $sequence[$round->id] = $round->sequence();
        }

        $scores = [];
        foreach ($this->overalls->forFacilities($facilityIds) as $row) {
            if (isset($sequence[$row['round_id']])) {
                $scores[$row['facility_id']][] = ['sequence' => $sequence[$row['round_id']], 'overall' => $row['overall']];
            }
        }

        return array_map(function (array $list): array {
            usort($list, fn (array $a, array $b): int => $b['sequence'] <=> $a['sequence']);

            return $list;
        }, $scores);
    }

    /**
     * Distinct facilities per assessor (name + device) per local day, for the days the batch's
     * visits fall on.
     *
     * @param  list<ParsedSubmission>  $submissions
     * @return array<string, array<int, true>>
     */
    private function assessorDays(array $submissions): array
    {
        $dates = [];
        foreach ($submissions as $submission) {
            $dates[$submission->visitDate()->toDateString()] = true;
        }

        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT assessor_name, device_id, (started_at AT TIME ZONE ?)::date::text AS day, facility_id
            FROM assessments
            WHERE (started_at AT TIME ZONE ?)::date = ANY(?::date[])
            SQL, [config('app.timezone'), config('app.timezone'), '{'.implode(',', array_keys($dates)).'}']);

        $days = [];
        foreach ($rows as $row) {
            $key = ValidationContext::assessorDayKey((string) $row->assessor_name, $row->device_id === null ? null : (string) $row->device_id, (string) $row->day);
            $days[$key][(int) $row->facility_id] = true;
        }

        return $days;
    }

    /**
     * The highest published version's ID and config, as round_aggregates picks it. A published
     * config the calculator cannot apply throws here: that is a setup error, not a submission's
     * fault.
     *
     * @return array{0: ?int, 1: ?RuleConfig}
     */
    private function ruleVersion(): array
    {
        $row = DB::table('scoring_rule_versions')
            ->whereNotNull('published_at')
            ->orderByDesc('version')
            ->first(['id', 'config']);

        if ($row === null) {
            return [null, null];
        }

        return [(int) $row->id, RuleConfig::fromArray((array) json_decode((string) $row->config, true, flags: JSON_THROW_ON_ERROR))];
    }

    /** @return array<string, true> */
    private function knownFormVersions(): array
    {
        $known = [];
        foreach (FieldMap::known() as $source => $versions) {
            foreach ($versions as $version) {
                $known["{$source}|{$version}"] = true;
            }
        }

        return $known;
    }
}
