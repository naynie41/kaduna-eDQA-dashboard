<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\DTOs\SubmissionEnvelope;
use App\Domain\Ingestion\Support\IsoTime;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\Enums\Dimension;
use Carbon\CarbonImmutable;

/**
 * Reads a SubmissionEnvelope into a ParsedSubmission through the field map for its (source, form
 * version) (ARCHITECTURE.md §5.5). It reads only the envelope, so it cannot tell the sources
 * apart: each source's PayloadNormalizer has absorbed its API's shape already.
 *
 * It never throws on bad data: anything it cannot read is passed through raw (rawStart and rawEnd
 * keep the original text, references stay text) so validation can quarantine it, and nothing is
 * guessed (CLAUDE.md hard rule 12):
 *
 * - a (source, version) with no map gives formVersionKnown = false and the minimal fields only
 *   (identity, version, submission time, device), never a read through another version's map;
 * - a time is read only when it is strict ISO 8601 ("FEBRUARY" is not a date);
 * - scored questions are every leaf whose name, after the form's renames, matches the map's
 *   pattern; each answer is mapped with the published scoring rule version's choice_map (Q-25);
 *   an answer the map does not know is 'invalid' (ITEMS_INCOMPLETE quarantines it); an unanswered
 *   question (null) is skipped;
 * - a number in a scored question is a legacy typed score: it goes into rawNumericScores, keyed by
 *   question name, never into items (Q-16).
 *
 * A map with item rules (the real KoboToolbox form, D-65) is read rule by rule instead of by name:
 *
 * - the visit's mode decides which rules apply (D-70). A monthly visit reads the monthly rules and
 *   covers the one slot of its review_month; a quarterly visit reads the rules of its quarter, each
 *   in its own month slot. The round is the reviewed period (D-71). A mode, month or quarter it
 *   cannot read gives no items and no round, never a guess;
 * - 'choice' (availability, D-66): the choice map; unanswered, or a service not offered, is N/A;
 * - 'count3' (consistency, D-67): an answer k of 0–3 is k passes of 3 checks; unanswered is N/A;
 *   anything else is invalid;
 * - 'match' (validity, D-68): every number entered and equal passes, any missing or different
 *   fails, none entered is N/A, a non-number is invalid;
 * - a community visit or DDU at LGA level is out of scope (D-74).
 */
final class SubmissionParser
{
    public function __construct(
        private readonly CurrentRuleVersion $rules,
    ) {}

    public function parse(SubmissionEnvelope $envelope): ParsedSubmission
    {
        $meta = $envelope->meta;
        $data = $envelope->data;
        $map = FieldMap::for($meta->source, $meta->formVersion);
        $submittedAt = $meta->submittedAt ?? $meta->updatedAt
            ?? CarbonImmutable::createFromTimestampUTC(0); // unreadable: ROUND_WINDOW will hold it

        if ($map === null) {
            return new ParsedSubmission(
                source: $meta->source, externalId: $meta->externalId,
                instanceId: $meta->instanceId, deprecatedId: $meta->deprecatedId, formVersion: $meta->formVersion, formVersionKnown: false,
                submittedAt: $submittedAt, startedAt: null, endedAt: null, rawStart: '', rawEnd: '',
                lgaRef: null, wardRef: null, facilityRef: null, roundYear: null, roundQuarter: null,
                assessorName: '', deviceId: $meta->deviceId, gps: null, items: [], rawNumericScores: null,
            );
        }

        $start = $map->value($data, 'started_at');
        $end = $map->value($data, 'ended_at');
        $rules = $map->itemRules();
        if ($rules === null) {
            [$items, $numeric] = $this->scored($data, $map);
            [$slots, $quarter] = [null, $this->quarter($map->value($data, 'round_quarter'), $map)];
        } else {
            [$items, $slots, $quarter] = $this->ruled($data, $map, $rules);
            $numeric = [];
        }

        return new ParsedSubmission(
            source: $meta->source,
            externalId: $meta->externalId,
            instanceId: $meta->instanceId,
            deprecatedId: $meta->deprecatedId,
            formVersion: $meta->formVersion,
            formVersionKnown: true,
            submittedAt: $submittedAt,
            startedAt: IsoTime::parse($start),
            endedAt: IsoTime::parse($end),
            rawStart: self::text($start) ?? '',
            rawEnd: self::text($end) ?? '',
            lgaRef: self::text($map->value($data, 'lga_code')),
            wardRef: self::text($map->value($data, 'ward_code')),
            facilityRef: self::text($map->value($data, 'facility_code')),
            roundYear: self::whole($map->value($data, 'round_year')),
            roundQuarter: $quarter,
            assessorName: self::text($map->value($data, 'assessor')) ?? '',
            deviceId: $meta->deviceId,
            gps: self::gps($map->value($data, 'gps')),
            items: $items,
            rawNumericScores: $numeric === [] ? null : $numeric,
            monthSlots: $slots,
            outOfScope: $map->outOfScope($data),
        );
    }

    /** The quarter as a number: 1–4, or q1–q4 for a map whose round_quarter_format is 'q'. */
    private function quarter(mixed $value, FieldMap $map): ?int
    {
        if ($map->quarterFormat() === 'q') {
            return is_string($value) && preg_match('/^q([1-4])$/D', $value, $m) === 1 ? (int) $m[1] : null;
        }

        return self::whole($value);
    }

    /**
     * The items a submission's answers give under the map's item rules, the month slots the visit
     * covers (null: all three) and its quarter.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<array{dimension: string, mode: string, slot: ?int, quarter: ?int, kind: string, code: string, paths: list<string>, service: ?string}>  $rules
     * @return array{0: list<ItemResponseData>, 1: list<int>|null, 2: ?int}
     */
    private function ruled(array $data, FieldMap $map, array $rules): array
    {
        $mode = $map->hasVisitMode() ? $map->visitMode($data) : 'quarterly';
        if ($mode === 'monthly') {
            $month = self::monthIndex($map->value($data, 'review_month'));
            if ($month === null) {
                return [[], null, null];
            }
            [$slots, $quarter] = [[$month % 3 + 1], intdiv($month, 3) + 1];
        } elseif ($mode === 'quarterly') {
            $quarter = $this->quarter($map->value($data, 'round_quarter'), $map);
            if ($quarter === null) {
                return [[], null, null];
            }
            $slots = null;
        } else {
            return [[], null, null];
        }

        $choices = $this->rules->config()->choiceMap ?? [];
        $items = [];
        foreach ($rules as $rule) {
            if ($rule['mode'] !== $mode || ($mode === 'quarterly' && $rule['quarter'] !== $quarter)) {
                continue;
            }
            $slot = $slots === null ? (int) $rule['slot'] : $slots[0];
            $dimension = Dimension::tryFrom($rule['dimension']);
            if ($dimension === null || $slot < 1 || $slot > 3) {
                continue;
            }
            array_push($items, ...self::ruleItems($rule, $data, $dimension, $slot, $choices));
        }

        return [$items, $slots, $quarter];
    }

    /**
     * One rule's items.
     *
     * @param  array{dimension: string, mode: string, slot: ?int, quarter: ?int, kind: string, code: string, paths: list<string>, service: ?string}  $rule
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $choices
     * @return list<ItemResponseData>
     */
    private static function ruleItems(array $rule, array $data, Dimension $dimension, int $slot, array $choices): array
    {
        $code = $rule['code'];
        $item = fn (string $value): ItemResponseData => new ItemResponseData($dimension, $slot, $code, $value, $value === 'pass' || $value === 'fail');
        $checks = $rule['kind'] === 'count3' ? 3 : 1;
        $notApplicable = array_fill(0, $checks, $item('na'));

        if ($rule['service'] !== null && FieldMap::get($data, $rule['service']) !== 'yes') {
            return $notApplicable; // the facility does not offer the service (D-66)
        }
        $values = array_map(fn (string $path): mixed => FieldMap::get($data, $path), $rule['paths']);

        switch ($rule['kind']) {
            case 'choice':
                if ($values[0] === null) {
                    return $notApplicable;
                }
                $outcome = is_string($values[0]) && isset($choices[$values[0]]) ? $choices[$values[0]] : ItemResponseData::INVALID;

                return [$item(in_array($outcome, ItemResponseData::VALUES, true) ? $outcome : ItemResponseData::INVALID)];
            case 'count3':
                if ($values[0] === null) {
                    return $notApplicable;
                }
                $k = is_int($values[0]) ? $values[0] : (is_string($values[0]) && preg_match('/^[0-3]$/D', $values[0]) === 1 ? (int) $values[0] : null);
                if ($k === null || $k < 0 || $k > 3) {
                    return [$item(ItemResponseData::INVALID)];
                }

                return [...array_fill(0, $k, $item('pass')), ...array_fill(0, 3 - $k, $item('fail'))];
            case 'match':
                $entered = array_values(array_filter($values, fn (mixed $v): bool => $v !== null && $v !== ''));
                if ($entered === []) {
                    return $notApplicable;
                }
                foreach ($entered as $v) {
                    if (! is_int($v) && ! is_float($v) && ! (is_string($v) && is_numeric($v))) {
                        return [$item(ItemResponseData::INVALID)];
                    }
                }
                $numbers = array_map(fn (mixed $v): float => (float) $v, $entered);
                $allEqual = count(array_unique(array_map(fn (float $n): string => (string) $n, $numbers))) === 1;

                return [$item(count($entered) === count($values) && $allEqual ? 'pass' : 'fail')];
            default:
                return [$item(ItemResponseData::INVALID)];
        }
    }

    /** 0 (January) to 11 (December), from a month's name or its three letters; null otherwise. */
    private static function monthIndex(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }
        $months = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
        $name = strtolower(trim($value));
        foreach ($months as $i => $month) {
            if ($name === $month || $name === substr($month, 0, 3)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Every scored question in the answers, in the order it arrives.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{0: list<ItemResponseData>, 1: array<string, float>}
     */
    private function scored(array $data, FieldMap $map): array
    {
        $choices = $this->rules->config()->choiceMap ?? []; // no published version: every answer is invalid
        $items = [];
        $numeric = [];

        $walk = function (array $group) use (&$walk, $map, $choices, &$items, &$numeric): void {
            foreach ($group as $name => $value) {
                if (! is_string($name)) {
                    continue;
                }
                $scored = $map->scored($name);
                if ($scored === null) {
                    // A group: look inside it (a list is a repeat, which the sources serve separately).
                    if (is_array($value) && ! array_is_list($value)) {
                        $walk($value);
                    }

                    continue;
                }

                [$dimension, $slot, $code, $canonical] = $scored;
                if ($value === null) {
                    continue; // unanswered or not relevant: a missing answer
                }
                if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                    $numeric[$canonical] = (float) $value; // a legacy typed score (Q-16)

                    continue;
                }
                $outcome = is_string($value) && isset($choices[$value]) ? $choices[$value] : ItemResponseData::INVALID;
                if (! in_array($outcome, ItemResponseData::VALUES, true)) {
                    $outcome = ItemResponseData::INVALID;
                }
                $items[] = new ItemResponseData($dimension, $slot, $code, $outcome, $outcome === 'pass' || $outcome === 'fail');
            }
        };
        $walk($data);

        return [$items, $numeric];
    }

    /** A scalar as text, as submitted; null for null, a group or a list. */
    private static function text(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => null,
        };
    }

    /** A whole number, from a number or numeric text; null otherwise. */
    private static function whole(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if ((is_float($value) || (is_string($value) && is_numeric($value))) && (float) $value === floor((float) $value) && abs((float) $value) < PHP_INT_MAX) {
            return (int) $value;
        }

        return null;
    }

    /**
     * A geopoint to lat and lng, from either of its two standard forms: GeoJSON ({"type": "Point",
     * "coordinates": [lng, lat, alt]}, as ODK Central's OData serves it) or the XForms text
     * "lat lng [alt [accuracy]]" (as KoboToolbox's data API serves it). Null when it is neither.
     * An accuracy is kept when given (GeoJSON properties.accuracy, or the text's fourth part).
     *
     * @return array<string, float>|null
     */
    private static function gps(mixed $value): ?array
    {
        if (is_string($value)) {
            $parts = preg_split('/\s+/', trim($value)) ?: [];
            if (count($parts) < 2 || count($parts) > 4 || array_filter($parts, fn (string $p): bool => ! is_numeric($p)) !== []) {
                return null;
            }
            [$lat, $lng] = [(float) $parts[0], (float) $parts[1]];
            if (abs($lat) > 90 || abs($lng) > 180) {
                return null;
            }
            $gps = ['lat' => $lat, 'lng' => $lng];
            if (isset($parts[3])) {
                $gps['accuracy'] = (float) $parts[3];
            }

            return $gps;
        }
        $coordinates = is_array($value) ? ($value['coordinates'] ?? null) : null;
        if (! is_array($coordinates) || ! is_numeric($coordinates[0] ?? null) || ! is_numeric($coordinates[1] ?? null)) {
            return null;
        }
        $gps = ['lat' => (float) $coordinates[1], 'lng' => (float) $coordinates[0]];
        $accuracy = is_array($value['properties'] ?? null) ? ($value['properties']['accuracy'] ?? null) : null;
        if (is_numeric($accuracy)) {
            $gps['accuracy'] = (float) $accuracy;
        }

        return $gps;
    }
}
