<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Kobo;

use App\Domain\Ingestion\Contracts\PayloadNormalizer;
use App\Domain\Ingestion\DTOs\EnvelopeAttachment;
use App\Domain\Ingestion\DTOs\SubmissionEnvelope;
use App\Domain\Ingestion\DTOs\SubmissionMeta;
use App\Domain\Ingestion\Support\IsoTime;
use Carbon\CarbonImmutable;

/**
 * One row of the KPI API v2 data endpoint → SubmissionEnvelope (ARCHITECTURE.md §5.2). Where the
 * shape differs from ODK Central's, it is absorbed here, so the parser cannot tell the sources
 * apart:
 *
 * - group paths are flat keys ("facility/lga"): unflattened into nested groups, as OData nests
 *   them. A repeat (a list of rows whose keys carry the repeat's path) is unflattened row by row;
 * - Kobo's own fields are read into meta: _id → externalId (the same through edits),
 *   meta/instanceID (else "uuid:" + _uuid) and meta/deprecatedID, _submission_time (written with no
 *   zone: UTC), __version__ → formVersion (the deployment's uid), _submitted_by → submitter, the
 *   form's deviceid → deviceId, _attachments → attachments (name, type, download URL);
 * - every other underscore field (_status, _geolocation, _tags, _notes, _validation_status, …),
 *   formhub/* and meta/* stay out of the answers. Nothing is dropped from the stored payload: it is
 *   kept verbatim (hard rule 8), this only reads it.
 *
 * Pure and deterministic, and it never throws on malformed input (the parser's guarantee): what it
 * cannot read is null or empty, and a key it cannot nest (one that clashes with another's group)
 * stays flat in the answers rather than being lost. The one clash with no flat place, a top-level
 * answer with the same name as a group, keeps the group.
 */
final class KoboPayloadNormalizer implements PayloadNormalizer
{
    public const SOURCE = 'kobo';

    /** A time as Kobo writes _submission_time: no zone, meaning UTC. */
    private const NAIVE_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?$/';

    /** Groups that are Kobo's or the form's metadata, never answers. */
    private const SYSTEM_GROUPS = ['meta', 'formhub'];

    public function normalise(array $payload): SubmissionEnvelope
    {
        $uuid = self::text($payload['_uuid'] ?? null);
        $instanceId = self::text($payload['meta/instanceID'] ?? null)
            ?? self::text(is_array($payload['meta'] ?? null) ? ($payload['meta']['instanceID'] ?? null) : null)
            ?? ($uuid === null ? null : (str_starts_with($uuid, 'uuid:') ? $uuid : "uuid:{$uuid}"));

        return new SubmissionEnvelope(
            new SubmissionMeta(
                source: self::SOURCE,
                externalId: self::text($payload['_id'] ?? null),
                instanceId: $instanceId ?? '',
                deprecatedId: self::text($payload['meta/deprecatedID'] ?? null)
                    ?? self::text(is_array($payload['meta'] ?? null) ? ($payload['meta']['deprecatedID'] ?? null) : null),
                submittedAt: self::time($payload['_submission_time'] ?? null),
                updatedAt: null, // Kobo submissions carry no edit time (KoboClient)
                formVersion: self::text($payload['__version__'] ?? null) ?? '',
                submitter: self::text($payload['_submitted_by'] ?? null),
                deviceId: self::text($payload['deviceid'] ?? null),
                attachments: self::attachments($payload['_attachments'] ?? null),
            ),
            self::answers($payload),
        );
    }

    /**
     * The answers, nested by group path.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function answers(array $payload): array
    {
        $data = [];
        foreach ($payload as $key => $value) {
            if (! is_string($key) || $key === '' || str_starts_with($key, '_')) {
                continue;
            }
            $first = explode('/', $key, 2)[0];
            if (in_array($first, self::SYSTEM_GROUPS, true) || $key === 'deviceid') {
                continue;
            }
            self::place($data, $key, self::value($value, $key));
        }

        return $data;
    }

    /**
     * Puts a value at its slash path; a path that clashes with what is there (a group where an
     * answer is, or the reverse) is kept flat under its full key.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function place(array &$data, string $key, mixed $value): void
    {
        $steps = explode('/', $key);
        if (in_array('', $steps, true)) {
            $data[$key] = $value; // "a//b", "a/", "/a": not a path

            return;
        }

        $node = &$data;
        foreach ($steps as $i => $step) {
            $last = $i === count($steps) - 1;
            if ($last) {
                if (array_key_exists($step, $node) && is_array($node[$step]) && ! array_is_list($node[$step])) {
                    if (count($steps) > 1) {
                        $data[$key] = $value; // a group is already here
                    } // a top-level answer named as a group cannot sit beside it: the group stays (the raw payload keeps both)

                    return;
                }
                $node[$step] = $value;

                return;
            }
            if (! array_key_exists($step, $node)) {
                $node[$step] = [];
            } elseif (! is_array($node[$step]) || array_is_list($node[$step])) {
                $data[$key] = $value; // an answer (or a repeat) is already here

                return;
            }
            $node = &$node[$step];
        }
    }

    /** A repeat's rows unflattened too: each row's keys carry the repeat's own path. */
    private static function value(mixed $value, string $path): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $value;
        }

        return array_map(function (mixed $row) use ($path): mixed {
            if (! is_array($row) || array_is_list($row)) {
                return $row;
            }
            $nested = self::answers($row);
            foreach (explode('/', $path) as $step) {
                if (! is_array($nested[$step] ?? null) || array_is_list($nested[$step])) {
                    return self::answers($row);
                }
                $nested = $nested[$step];
            }

            return $nested;
        }, $value);
    }

    /** @return list<EnvelopeAttachment> */
    private static function attachments(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $attachments = [];
        foreach ($value as $attachment) {
            if (! is_array($attachment) || ($attachment['is_deleted'] ?? false) === true) {
                continue;
            }
            $name = self::text($attachment['media_file_basename'] ?? null);
            $file = self::text($attachment['filename'] ?? null);
            $name ??= $file === null ? null : basename($file);
            if ($name === null) {
                continue;
            }
            $attachments[] = new EnvelopeAttachment($name, self::text($attachment['mimetype'] ?? null), self::text($attachment['download_url'] ?? null));
        }

        return $attachments;
    }

    /** _submission_time: no zone means UTC; a time with its zone is read as written. */
    private static function time(mixed $value): ?CarbonImmutable
    {
        if (is_string($value) && preg_match(self::NAIVE_TIME, $value) === 1) {
            $value .= 'Z';
        }

        return IsoTime::parse($value);
    }

    /** A scalar as text, as sent; null for null, empty, a group or a list. */
    private static function text(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value === '' ? null : $value,
            is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }
}
