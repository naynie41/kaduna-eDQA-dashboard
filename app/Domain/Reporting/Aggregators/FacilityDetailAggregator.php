<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Queries\AssessmentSourceQuery;
use App\Domain\Reporting\DTOs\FacilityDetail;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Queries\FacilityRoundOverallsQuery;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Round\Models\Round;
use App\Domain\Round\MonthSlot;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
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
 */
final class FacilityDetailAggregator
{
    /** Most recent audit entries returned; the full trail stays in activity_log. */
    public const ACTIVITY_LIMIT = 100;

    /** @param  array<string, string>  $rules  edqa.validation.rules: soft flags are among their codes */
    public function __construct(
        private readonly FacilityScoresSql $sql,
        private readonly RoundSelection $rounds,
        private readonly RuleVersionForRound $versions,
        private readonly FacilityRoundOverallsQuery $overalls,
        private readonly AssessmentSourceQuery $sources,
        #[Config('edqa.validation.rules')] private readonly array $rules,
    ) {}

    public function forFacility(Facility $facility, ?Round $round = null, ReportFilter $filter = new ReportFilter): FacilityDetail
    {
        $facility->loadMissing(['lga:id,name', 'ward:id,name']);
        $assessments = Assessment::query()
            ->where('facility_id', $facility->id)
            ->whereIn('status', FacilityScoresSql::ACCEPTED)
            ->with('round')
            ->get();

        $round ??= $this->roundInView(array_map(intval(...), $assessments->pluck('round_id')->all()), $filter);
        $assessment = $round === null ? null : $assessments->first(fn (Assessment $a): bool => $a->round_id === $round->id);
        $rules = $round === null ? null : $this->sql->configFor($round);
        $versionId = $round === null ? null : $this->versionId($round);
        $slots = $assessment === null || $versionId === null ? [] : $this->slotScores($assessment->id, $versionId);
        $source = $assessment === null ? null : $this->sources->forSubmission($assessment->submission_id);
        $activity = $this->activity($assessments->modelKeys());

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
                'label' => self::roundLabel($round),
                'year' => $round->year,
                'quarter' => $round->quarter,
            ],
            assessment: $assessment === null ? null : [
                'id' => $assessment->id,
                'assessed_on' => $assessment->started_at->setTimezone((string) config('app.timezone'))->toDateString(),
                'assessor' => (string) $assessment->assessor_name,
                'status' => (string) $assessment->status,
            ],
            scores: $this->scoreCard($assessment?->id, $versionId, $rules),
            items: $assessment === null ? [] : $this->items($assessment->id, $round, $slots, $rules),
            history: $this->history($facility->id, $round),
            flags: $assessment === null ? [] : $this->flags($assessment, $activity['overrides']),
            attachments: $source === null ? [] : $source->attachments,
            activity: $activity['entries'],
            odk: ['instance_id' => $source?->instanceId, 'url' => $source?->centralUrl],
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
     * @return list<array{dimension: string, dimension_label: string, slot: int, month: string, score: ScoreValue, items: list<array{code: string, label: ?string, result: ?string}>}>
     */
    private function items(int $assessmentId, Round $round, array $slots, ?RuleConfig $rules): array
    {
        $groups = [];
        $responses = ItemResponse::query()->where('assessment_id', $assessmentId)->orderBy('month_slot')->orderBy('id')->get();
        foreach (Dimension::cases() as $dimension) {
            foreach ([1, 2, 3] as $slot) {
                $items = $responses->filter(fn (ItemResponse $r): bool => $r->dimension === $dimension && $r->month_slot === $slot)
                    ->map(fn (ItemResponse $r): array => [
                        'code' => (string) $r->item_code,
                        'label' => self::itemLabel($dimension, (string) $r->item_code),
                        'result' => self::result((string) $r->value, $r->is_applicable, $rules),
                    ])->values()->all();
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
     * The facility's overall in every round it was assessed in, oldest first.
     *
     * @return list<array{round_id: int, label: string, overall: ScoreValue, selected: bool}>
     */
    private function history(int $facilityId, ?Round $selected): array
    {
        $overalls = $this->overalls->forFacilities([$facilityId]);
        $rounds = Round::query()->whereIn('id', array_column($overalls, 'round_id'))->get()->keyBy('id');

        $points = [];
        foreach ($overalls as $row) {
            $round = $rounds->get($row['round_id']);
            if ($round === null) {
                continue;
            }
            $points[] = [
                'sort' => $round->year * 10 + $round->quarter,
                'round_id' => $round->id,
                'label' => self::roundLabel($round),
                'overall' => ScoreValue::fromNullable($row['overall'], $this->sql->configFor($round)),
                'selected' => $round->id === $selected?->id,
            ];
        }
        usort($points, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $p): array => [
            'round_id' => $p['round_id'], 'label' => $p['label'], 'overall' => $p['overall'], 'selected' => $p['selected'],
        ], $points);
    }

    /**
     * Soft flags on the assessment, then those an administrator overrode (who, when, why).
     * SLOT_ALL_NA:… entries are scoring notes, not flags, so they are left out.
     *
     * @param  array<int, list<array{code: string, by: ?string, at: string, reason: ?string}>>  $overrides  by assessment id, newest first
     * @return list<array{code: string, label: string, overridden: array{by: ?string, at: string, reason: ?string}|null}>
     */
    private function flags(Assessment $assessment, array $overrides): array
    {
        /** @var list<string> $codes */
        $codes = $assessment->flags;
        $open = array_values(array_filter($codes, fn (string $code): bool => isset($this->rules[$code])));

        $flags = array_map(fn (string $code): array => ['code' => $code, 'label' => self::flagLabel($code), 'overridden' => null], $open);
        $seen = $open;
        foreach ($overrides[$assessment->id] ?? [] as $override) {
            if (in_array($override['code'], $seen, true)) {
                continue;
            }
            $seen[] = $override['code'];
            $flags[] = [
                'code' => $override['code'],
                'label' => self::flagLabel($override['code']),
                'overridden' => ['by' => $override['by'], 'at' => $override['at'], 'reason' => $override['reason']],
            ];
        }

        return $flags;
    }

    private static function flagLabel(string $code): string
    {
        $label = __("validation_rules.{$code}.name");

        return is_string($label) ? $label : $code;
    }

    /**
     * Audit entries on the facility's assessments and their item responses, newest first:
     * who, when, what changed and why. Score rows are derived, so their entries are left out;
     * IP and user agent stay in activity_log (SECURITY.md §4), never in the response.
     *
     * @param  list<int>  $assessmentIds
     * @return array{entries: list<array{id: int, at: string, actor: ?string, event: string, subject: string, item_code: ?string, changes: list<array{field: string, old: ?string, new: ?string}>, reason: ?string}>, overrides: array<int, list<array{code: string, by: ?string, at: string, reason: ?string}>>}
     */
    private function activity(array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return ['entries' => [], 'overrides' => []];
        }

        $ids = '{'.implode(',', $assessmentIds).'}';
        $assessmentType = (new Assessment)->getMorphClass();
        $itemType = (new ItemResponse)->getMorphClass();
        $rows = DB::select(<<<'SQL'
            SELECT a.id, a.created_at, a.event, a.subject_type, a.subject_id, a.properties, u.name AS actor, ir.item_code
            FROM activity_log a
            LEFT JOIN users u ON a.causer_type = ? AND u.id = a.causer_id
            LEFT JOIN item_responses ir ON a.subject_type = ? AND ir.id = a.subject_id
            WHERE (a.subject_type = ? AND a.subject_id = ANY(?::bigint[]))
               OR (a.subject_type = ? AND a.subject_id IN (SELECT id FROM item_responses WHERE assessment_id = ANY(?::bigint[])))
            ORDER BY a.id DESC
            LIMIT ?
            SQL, [(new User)->getMorphClass(), $itemType, $assessmentType, $ids, $itemType, $ids, self::ACTIVITY_LIMIT]);

        $entries = [];
        $overrides = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $properties */
            $properties = json_decode((string) ($row->properties ?? '{}'), true) ?: [];
            $at = CarbonImmutable::parse((string) $row->created_at)->toIso8601String();
            $actor = $row->actor === null ? null : (string) $row->actor;
            $reason = is_string($properties['reason'] ?? null) ? $properties['reason'] : null;
            $event = (string) ($row->event ?? 'updated');

            $entries[] = [
                'id' => (int) $row->id,
                'at' => $at,
                'actor' => $actor,
                'event' => $event,
                'subject' => $row->subject_type === $assessmentType ? 'assessment' : 'item',
                'item_code' => $row->item_code === null ? null : (string) $row->item_code,
                'changes' => self::changes($properties),
                'reason' => $reason,
            ];

            if ($event === 'flag_overridden' && $row->subject_type === $assessmentType && is_string($properties['code'] ?? null)) {
                $overrides[(int) $row->subject_id][] = ['code' => $properties['code'], 'by' => $actor, 'at' => $at, 'reason' => $reason];
            }
        }

        return ['entries' => $entries, 'overrides' => $overrides];
    }

    /**
     * properties.old / properties.attributes as field, old, new; lists read "a, b".
     *
     * @param  array<string, mixed>  $properties
     * @return list<array{field: string, old: ?string, new: ?string}>
     */
    private static function changes(array $properties): array
    {
        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];
        $new = is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [];

        $changes = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $before = self::text($old[$field] ?? null);
            $after = self::text($new[$field] ?? null);
            if ($before !== $after) {
                $changes[] = ['field' => (string) $field, 'old' => $before, 'new' => $after];
            }
        }

        return $changes;
    }

    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) && array_filter($value, 'is_scalar') === $value => implode(', ', array_map('strval', $value)),
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    private static function roundLabel(Round $round): string
    {
        return (string) __('reporting.round_label', ['quarter' => (string) $round->quarter, 'year' => (string) $round->year]);
    }
}
