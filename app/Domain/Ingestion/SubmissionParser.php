<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\DTOs\SubmissionEnvelope;
use App\Domain\Ingestion\Support\IsoTime;
use App\Domain\Scoring\CurrentRuleVersion;
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
        [$items, $numeric] = $this->scored($data, $map);

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
            roundQuarter: self::whole($map->value($data, 'round_quarter')),
            assessorName: self::text($map->value($data, 'assessor')) ?? '',
            deviceId: $meta->deviceId,
            gps: self::gps($map->value($data, 'gps')),
            items: $items,
            rawNumericScores: $numeric === [] ? null : $numeric,
        );
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
     * A geopoint as GeoJSON ({"type": "Point", "coordinates": [lng, lat, alt]}) to lat and lng;
     * null when it is not one. An accuracy given in properties.accuracy is kept (ODK Central's
     * OData leaves it out today).
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
