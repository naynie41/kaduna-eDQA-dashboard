<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Scoring\CurrentRuleVersion;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads one entry of an ODK Central OData Submissions response into a ParsedSubmission through
 * its form version's FieldMap (ARCHITECTURE.md §5.5).
 *
 * It never throws on bad data: anything it cannot read is passed through raw (rawStart and rawEnd
 * keep the original text, references stay text) so validation can quarantine it, and nothing is
 * guessed (CLAUDE.md hard rule 12):
 *
 * - an unknown __system.formVersion gives formVersionKnown = false and the minimal fields only
 *   (identity, version, submission time, device), never a read through the default map;
 * - a time is read only when it is strict ISO 8601 ("FEBRUARY" is not a date);
 * - scored questions are every leaf whose name, after the version's renames, matches the map's
 *   pattern; each answer is mapped with the published scoring rule version's choice_map (Q-25);
 *   an answer the map does not know is 'invalid' (ITEMS_INCOMPLETE quarantines it); an unanswered
 *   question (null) is skipped;
 * - a number in a scored question is a legacy typed score: it goes into rawNumericScores, keyed by
 *   question name, never into items (Q-16).
 */
final class SubmissionParser
{
    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})$/';

    /** ODK's own parts of an entry: never form questions. */
    private const SYSTEM_KEYS = ['__id', '__system', 'meta'];

    public function __construct(
        private readonly CurrentRuleVersion $rules,
    ) {}

    /** @param  array<array-key, mixed>  $payload */
    public function parse(array $payload): ParsedSubmission
    {
        $version = self::text(FieldMap::get($payload, '__system.formVersion')) ?? '';
        $map = FieldMap::resolve($version);
        $instanceId = self::text(FieldMap::get($payload, 'meta.instanceID')) ?? self::text($payload['__id'] ?? null) ?? '';
        $deprecatedId = self::text(FieldMap::get($payload, 'meta.deprecatedID'));
        $submittedAt = self::time(FieldMap::get($payload, '__system.submissionDate'))
            ?? self::time(FieldMap::get($payload, '__system.updatedAt'))
            ?? CarbonImmutable::createFromTimestampUTC(0); // unreadable: ROUND_WINDOW will hold it
        $deviceId = self::text(FieldMap::get($payload, '__system.deviceId'));

        if ($map === null) {
            return new ParsedSubmission(
                instanceId: $instanceId, deprecatedId: $deprecatedId, formVersion: $version, formVersionKnown: false,
                submittedAt: $submittedAt, startedAt: null, endedAt: null, rawStart: '', rawEnd: '',
                lgaRef: null, wardRef: null, facilityRef: null, roundYear: null, roundQuarter: null,
                assessorName: '', deviceId: $deviceId, gps: null, items: [], rawNumericScores: null,
            );
        }

        $start = $map->value($payload, 'started_at');
        $end = $map->value($payload, 'ended_at');
        [$items, $numeric] = $this->scored($payload, $map);

        return new ParsedSubmission(
            instanceId: $instanceId,
            deprecatedId: $deprecatedId,
            formVersion: $version,
            formVersionKnown: true,
            submittedAt: $submittedAt,
            startedAt: self::time($start),
            endedAt: self::time($end),
            rawStart: self::text($start) ?? '',
            rawEnd: self::text($end) ?? '',
            lgaRef: self::text($map->value($payload, 'lga_code')),
            wardRef: self::text($map->value($payload, 'ward_code')),
            facilityRef: self::text($map->value($payload, 'facility_code')),
            roundYear: self::whole($map->value($payload, 'round_year')),
            roundQuarter: self::whole($map->value($payload, 'round_quarter')),
            assessorName: self::text($map->value($payload, 'assessor')) ?? '',
            deviceId: $deviceId,
            gps: self::gps($map->value($payload, 'gps')),
            items: $items,
            rawNumericScores: $numeric === [] ? null : $numeric,
        );
    }

    /**
     * Every scored question in the entry, in the order it arrives.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{0: list<ItemResponseData>, 1: array<string, float>}
     */
    private function scored(array $payload, FieldMap $map): array
    {
        $choices = $this->rules->config()->choiceMap ?? []; // no published version: every answer is invalid
        $items = [];
        $numeric = [];

        $walk = function (array $group, bool $top) use (&$walk, $map, $choices, &$items, &$numeric): void {
            foreach ($group as $name => $value) {
                if (! is_string($name) || ($top && in_array($name, self::SYSTEM_KEYS, true))) {
                    continue;
                }
                $scored = $map->scored($name);
                if ($scored === null) {
                    // A group: look inside it (a list is a repeat, which ODK serves separately).
                    if (is_array($value) && ! array_is_list($value)) {
                        $walk($value, false);
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
        $walk($payload, true);

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

    /** A strict ISO 8601 date and time with its offset; null for anything else. */
    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::ISO_8601, $value) !== 1) {
            return null;
        }
        try {
            $time = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        // Out-of-range parts ("2026-13-45T99:00") roll over in PHP: refuse them.
        return $time->format('Y-m-d') === substr($value, 0, 10) && $time->format('H:i') === substr($value, 11, 5) ? $time : null;
    }

    /**
     * ODK's geopoint as GeoJSON ({"type": "Point", "coordinates": [lng, lat, alt]}) to lat and lng;
     * null when it is not one. Central's OData leaves the accuracy out today; one given in
     * properties.accuracy is kept.
     *
     * @return array<string, float>|null
     */
    private static function gps(mixed $value): ?array
    {
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
