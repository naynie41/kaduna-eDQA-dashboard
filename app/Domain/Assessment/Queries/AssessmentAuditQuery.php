<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Queries;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Validation\EventFlags;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;

/**
 * What only the admin facility view shows about a facility's assessments (ARCHITECTURE.md §9.3):
 * the audit trail, the soft flags with their overrides, and the latest portal correction. Read
 * from activity_log, so never for the public drawer (SECURITY.md §3.1): FacilityDetailAggregator
 * calls it for the admin audience only.
 */
final class AssessmentAuditQuery
{
    /** Most recent audit entries returned; the full trail stays in activity_log. */
    public const ACTIVITY_LIMIT = 100;

    /** @param  array<string, string>  $rules  edqa.validation.rules: soft flags are among their codes */
    public function __construct(
        #[Config('edqa.validation.rules')] private readonly array $rules,
    ) {}

    /**
     * Audit entries on the assessments and their item responses, newest first: who, when, what
     * changed and why. Score rows are derived, so their entries are left out; IP and user agent
     * stay in activity_log (SECURITY.md §4), never in the response.
     *
     * @param  list<int>  $assessmentIds
     * @return array{entries: list<array{id: int, at: string, actor: ?string, event: string, subject: string, item_code: ?string, changes: list<array{field: string, old: ?string, new: ?string}>, reason: ?string}>, overrides: array<int, list<array{code: string, by: ?string, at: string, reason: ?string}>>}
     */
    public function activity(array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return ['entries' => [], 'overrides' => []];
        }

        $ids = '{'.implode(',', $assessmentIds).'}';
        $assessmentType = (new Assessment)->getMorphClass();
        $itemType = (new ItemResponse)->getMorphClass();
        $rows = DB::select(<<<'SQL'
            SELECT a.id, a.created_at, a.event, a.subject_type, a.subject_id, a.properties, u.name AS actor,
                COALESCE(a.properties->>'key', ir.item_code) AS item_code
            FROM activity_log a
            LEFT JOIN users u ON a.causer_type = ? AND u.id = a.causer_id
            LEFT JOIN item_responses ir ON a.subject_type = ? AND ir.id = a.subject_id
            WHERE (a.subject_type = ? AND a.subject_id = ANY(?::bigint[]))
               OR (a.subject_type = ? AND a.subject_id IN (SELECT id FROM item_responses WHERE assessment_id = ANY(?::bigint[])))
               OR (a.subject_type = ? AND (a.properties->>'assessment_id')::bigint = ANY(?::bigint[]))
            ORDER BY a.id DESC
            LIMIT ?
            SQL, [(new User)->getMorphClass(), $itemType, $assessmentType, $ids, $itemType, $ids, $itemType, $ids, self::ACTIVITY_LIMIT]);

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
     * Soft flags on the assessment, then those an administrator overrode (who, when, why).
     * SLOT_ALL_NA:… entries are scoring notes, not flags, so they are left out.
     *
     * @param  array<int, list<array{code: string, by: ?string, at: string, reason: ?string}>>  $overrides  by assessment id, newest first (activity())
     * @return list<array{code: string, label: string, overridden: array{by: ?string, at: string, reason: ?string}|null}>
     */
    public function flags(Assessment $assessment, array $overrides): array
    {
        /** @var list<string> $codes */
        $codes = $assessment->flags;
        $open = array_values(array_filter($codes, fn (string $code): bool => isset($this->rules[$code]) || in_array($code, EventFlags::ALL, true)));

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

    /**
     * The latest portal correction of the assessment (its times or an item) made after the last
     * source edit that replaced corrections (source_edit_over_correction): who and when, or null.
     *
     * @return array{at: string, by: ?string}|null
     */
    public function corrected(Assessment $assessment): ?array
    {
        $type = $assessment->getMorphClass();
        $since = (int) DB::table('activity_log')
            ->where('subject_type', $type)->where('subject_id', $assessment->id)->where('event', 'source_edit_over_correction')
            ->max('id');
        $row = DB::table('activity_log as a')
            ->leftJoin('users as u', fn ($join) => $join->on('u.id', '=', 'a.causer_id')->where('a.causer_type', (new User)->getMorphClass()))
            ->where('a.event', 'corrected')->where('a.id', '>', $since)
            ->where(fn ($q) => $q->where(fn ($s) => $s->where('a.subject_type', $type)->where('a.subject_id', $assessment->id))
                ->orWhereRaw("(a.properties->>'assessment_id')::bigint = ?", [$assessment->id]))
            ->orderByDesc('a.id')
            ->first(['a.created_at', 'u.name']);

        return $row === null ? null : [
            'at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            'by' => $row->name === null ? null : (string) $row->name,
        ];
    }

    private static function flagLabel(string $code): string
    {
        $label = __("validation_rules.{$code}.name");

        return is_string($label) ? $label : $code;
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
}
