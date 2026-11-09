<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Assessment\Queries\AssessmentAuditQuery;
use App\Domain\Assessment\Support\ItemKey;
use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Queries\AssessmentSourceQuery;
use App\Domain\Reporting\DTOs\FacilityDetail;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Queries\FacilityRoundOverallsQuery;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\PublicScope;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Round\MonthSlot;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The facility drawer (ARCHITECTURE.md §9.2.7, GET /api/facilities/{id}): one facility's master
 * record, the score card and item responses of one round, its overall round over round, soft
 * flags with their overrides, attachments, the audit trail of its assessments and the ODK
 * reference.
 *
 * The round: the one asked for; else, of the rounds the dashboard filters put in view
 * (RoundSelection::current), the latest the facility was assessed in, which is the round its
 * table row shows; else the latest in view, with no assessment.
 *
 * Only accepted assessments (accepted or flagged), each round read under its own rule version
 * (D-32), as every other aggregate. One facility, so not cached: a handful of indexed queries.
 *
 * For the Public audience (the public drawer, D-77) only closed rounds exist: an open round asked
 * for is ignored, and the history leaves open rounds out. What only the admin facility view shows
 * (flags, attachments, the audit trail, the source, corrections) is not even computed; the public
 * resource is the whitelist on top (SECURITY.md §3.1).
 */
final class FacilityDetailAggregator
{
    public function __construct(
        private readonly FacilityScoresSql $sql,
        private readonly RoundSelection $rounds,
        private readonly RuleVersionForRound $versions,
        private readonly FacilityRoundOverallsQuery $overalls,
        private readonly AssessmentSourceQuery $sources,
        private readonly AssessmentAuditQuery $audit,
    ) {}

    public function forFacility(Facility $facility, ?Round $round = null, ReportFilter $filter = new ReportFilter): FacilityDetail
    {
        $public = PublicScope::restricts($filter->audience);
        $facility->loadMissing(['lga:id,name', 'ward:id,name']);
        $assessments = Assessment::query()
            ->where('facility_id', $facility->id)
            ->whereIn('status', FacilityScoresSql::ACCEPTED)
            ->when($public, fn ($q) => $q->whereHas('round', fn ($r) => $r->where('status', RoundStatus::Closed->value)))
            ->with('round')
            ->get();

        if ($public && $round !== null && $round->status !== RoundStatus::Closed) {
            $round = null;
        }
        $round ??= $this->roundInView(array_map(intval(...), $assessments->pluck('round_id')->all()), $filter);
        $assessment = $round === null ? null : $assessments->first(fn (Assessment $a): bool => $a->round_id === $round->id);
        $rules = $round === null ? null : $this->sql->configFor($round);
        $versionId = $round === null ? null : $this->versionId($round);
        $slots = $assessment === null || $versionId === null ? [] : $this->slotScores($assessment->id, $versionId);
        $source = $assessment === null || $public ? null : $this->sources->forSubmission($assessment->submission_id);
        $activity = $public ? ['entries' => [], 'overrides' => []] : $this->audit->activity($assessments->modelKeys());

        return new FacilityDetail(
            facility: [
                'id' => $facility->id,
                'code' => (string) $facility->code,
                'name' => (string) $facility->name,
                'lga' => (string) $facility->lga->name,
                'ward' => (string) $facility->ward->name,
                'level' => $facility->level->value,
                'level_label' => $facility->level->label(),
                'ownership' => $facility->ownership->value,
                'ownership_label' => $facility->ownership->label(),
                'active' => (bool) $facility->is_active,
            ],
            round: $round === null ? null : [
                'id' => $round->id,
                'label' => $round->label(),
                'year' => $round->year,
                'quarter' => $round->quarter,
                // Corrections are allowed while it is open (D-58; a closed round is pinned, D-32).
                'open' => $round->status === RoundStatus::Open,
            ],
            assessment: $assessment === null ? null : [
                'id' => $assessment->id,
                'assessed_on' => $assessment->started_at->setTimezone((string) config('app.timezone'))->toDateString(),
                'assessor' => (string) $assessment->assessor_name,
                'status' => (string) $assessment->status,
                'started_at' => $assessment->started_at->setTimezone((string) config('app.timezone'))->toIso8601String(),
                'ended_at' => $assessment->ended_at->setTimezone((string) config('app.timezone'))->toIso8601String(),
                // The latest portal correction the source has not since replaced (Q-23): the drawer
                // says the portal differs from the source, which still shows the old answers.
                'corrected' => $public ? null : $this->audit->corrected($assessment),
            ],
            scores: $this->scoreCard($assessment?->id, $versionId, $rules),
            items: $assessment === null ? [] : $this->items($assessment->id, $round, $slots, $rules),
            history: $this->history($facility->id, $round, $public),
            flags: $assessment === null || $public ? [] : $this->audit->flags($assessment, $activity['overrides']),
            attachments: $source === null ? [] : $source->attachments,
            activity: $activity['entries'],
            source: ['key' => $source?->sourceKey, 'name' => $source?->sourceName, 'instance_id' => $source?->instanceId, 'url' => $source?->url],
        );
    }

    /** @param  list<int>  $assessed  ids of the rounds the facility was assessed in */
    private function roundInView(array $assessed, ReportFilter $filter): ?Round
    {
        $inView = $this->rounds->current($filter); // time order
        $assessedInView = array_values(array_filter($inView, fn (Round $r): bool => in_array($r->id, $assessed, true)));

        return $assessedInView !== [] ? $assessedInView[count($assessedInView) - 1] : ($inView !== [] ? $inView[count($inView) - 1] : null);
    }

    private function versionId(Round $round): ?int
    {
        try {
            return $this->versions->id($round);
        } catch (NoPublishedRuleVersion) {
            return null;
        }
    }

    /**
     * Dimension = mean of its non-null month slots, overall = mean of the three (null when one is
     * all N/A), as FacilityScoresSql and round_aggregates compute them.
     *
     * @return array{availability: ScoreValue, consistency: ScoreValue, validity: ScoreValue, overall: ScoreValue}
     */
    private function scoreCard(?int $assessmentId, ?int $versionId, ?RuleConfig $rules): array
    {
        $row = $assessmentId === null || $versionId === null ? null : DB::selectOne(<<<'SQL'
            SELECT d.availability, d.consistency, d.validity, (d.availability + d.consistency + d.validity) / 3 AS overall
            FROM (
                SELECT avg(score) FILTER (WHERE dimension = 'availability') AS availability,
                    avg(score) FILTER (WHERE dimension = 'consistency') AS consistency,
                    avg(score) FILTER (WHERE dimension = 'validity') AS validity
                FROM assessment_scores
                WHERE assessment_id = ? AND rule_version_id = ?
            ) d
            SQL, [$assessmentId, $versionId]);

        return [
            'availability' => Scores::of($row?->availability, $rules),
            'consistency' => Scores::of($row?->consistency, $rules),
            'validity' => Scores::of($row?->validity, $rules),
            'overall' => Scores::of($row?->overall, $rules),
        ];
    }

    /** @return array<string, array<int, float|null>> stored slot scores, by dimension and slot */
    private function slotScores(int $assessmentId, int $versionId): array
    {
        $slots = [];
        foreach (DB::table('assessment_scores')->where('assessment_id', $assessmentId)->where('rule_version_id', $versionId)->get(['dimension', 'month_slot', 'score']) as $row) {
            $slots[(string) $row->dimension][(int) $row->month_slot] = is_numeric($row->score) ? (float) $row->score : null;
        }

        return $slots;
    }

    /**
     * Item responses by dimension × month, in form order. An item's result follows
     * ScoreCalculator: N/A when not applicable or when the round's choice map says so, else the
     * mapped pass or fail. Null only when no rule version can be read.
     *
     * @param  array<string, array<int, float|null>>  $slots
     * @return list<array{dimension: string, dimension_label: string, slot: int, month: string, score: ScoreValue, items: list<array{code: string, key: string, label: ?string, result: ?string, checks: array{passed: int, total: int}|null}>}>
     */
    private function items(int $assessmentId, Round $round, array $slots, ?RuleConfig $rules): array
    {
        $groups = [];
        $responses = ItemResponse::query()->where('assessment_id', $assessmentId)->orderBy('month_slot')->orderBy('id')->get();
        foreach (Dimension::cases() as $dimension) {
            foreach ([1, 2, 3] as $slot) {
                // One row per question: a question answered as several checks under one code (the
                // real Kobo form's consistency items, D-67) is one item whose result is the count
                // of checks passed, or N/A when none applies.
                $items = $responses->filter(fn (ItemResponse $r): bool => $r->dimension === $dimension && $r->month_slot === $slot)
                    ->groupBy(fn (ItemResponse $r): string => (string) $r->item_code)
                    ->map(function (Collection $checks, string $code) use ($dimension, $slot, $rules): array {
                        $results = $checks->map(fn (ItemResponse $r): ?string => self::result((string) $r->value, $r->is_applicable, $rules));
                        $single = $checks->count() === 1;
                        $passed = $results->filter(fn (?string $r): bool => $r === 'pass')->count();

                        return [
                            'code' => $code,
                            'key' => ItemKey::make($dimension, $slot, $code),
                            'label' => self::itemLabel($dimension, $code),
                            'result' => match (true) {
                                $single => $results->first(),
                                $results->contains(null) => null,
                                $results->every(fn (?string $r): bool => $r === 'na') => 'na',
                                default => (string) $passed,
                            },
                            'checks' => $single ? null : ['passed' => $passed, 'total' => $checks->count()],
                        ];
                    })->values()->all();
                if ($items === []) {
                    continue;
                }

                $groups[] = [
                    'dimension' => $dimension->value,
                    'dimension_label' => $dimension->label(),
                    'slot' => $slot,
                    'month' => MonthSlot::label($round->year, $round->quarter, $slot),
                    'score' => ScoreValue::fromNullable($slots[$dimension->value][$slot] ?? null, $rules),
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }

    private static function result(string $value, bool $applicable, ?RuleConfig $rules): ?string
    {
        if (! $applicable) {
            return 'na';
        }
        $outcome = $rules?->outcome($value);

        return in_array($outcome, ['pass', 'fail', 'na'], true) ? $outcome : null;
    }

    /** lang/en/items.php, when the form's labels are known (Q-02); null rather than the key. */
    private static function itemLabel(Dimension $dimension, string $code): ?string
    {
        $key = "items.{$dimension->value}.{$code}";
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : null;
    }

    /**
     * The facility's overall in every round it was assessed in (closed ones only for the public),
     * oldest first.
     *
     * @return list<array{round_id: int, label: string, overall: ScoreValue, selected: bool}>
     */
    private function history(int $facilityId, ?Round $selected, bool $closedOnly = false): array
    {
        $overalls = $this->overalls->forFacilities([$facilityId]);
        $rounds = Round::query()->whereIn('id', array_column($overalls, 'round_id'))
            ->when($closedOnly, fn ($q) => $q->where('status', RoundStatus::Closed->value))
            ->get()->keyBy('id');

        $points = [];
        foreach ($overalls as $row) {
            $round = $rounds->get($row['round_id']);
            if ($round === null) {
                continue;
            }
            $points[] = [
                'sort' => $round->year * 10 + $round->quarter,
                'round_id' => $round->id,
                'label' => $round->label(),
                'overall' => ScoreValue::fromNullable($row['overall'], $this->sql->configFor($round)),
                'selected' => $round->id === $selected?->id,
            ];
        }
        usort($points, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $p): array => [
            'round_id' => $p['round_id'], 'label' => $p['label'], 'overall' => $p['overall'], 'selected' => $p['selected'],
        ], $points);
    }
}
