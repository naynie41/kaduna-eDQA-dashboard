<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

use App\Domain\Ingestion\Contracts\PayloadNormalizer;
use App\Domain\Ingestion\DTOs\SubmissionEnvelope;
use App\Domain\Ingestion\DTOs\SubmissionMeta;
use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\Support\IsoTime;

/**
 * One entry of an ODK Central OData Submissions response → SubmissionEnvelope (ARCHITECTURE.md
 * §5.2). Central's own parts are read into meta and left out of the answers:
 *
 * - __id → externalId (the logical ID: every edited version keeps it);
 * - meta.instanceID → instanceId (this version; __id when missing); meta.deprecatedID;
 * - __system.submissionDate, updatedAt (strict ISO 8601), formVersion, submitterName (or
 *   submitterId), deviceId.
 *
 * Everything else is the answers, nested by group as OData serves them. Attachments are not in
 * the entry (Central lists them per submission), so the envelope names none.
 */
final class OdkPayloadNormalizer implements PayloadNormalizer
{
    public const SOURCE = 'odk';

    /** Central's own parts of an entry: never form questions. */
    private const SYSTEM_KEYS = ['__id', '__system', 'meta'];

    public function normalise(array $payload): SubmissionEnvelope
    {
        $externalId = self::text($payload['__id'] ?? null);

        $data = [];
        foreach ($payload as $key => $value) {
            if (! in_array($key, self::SYSTEM_KEYS, true)) {
                $data[$key] = $value;
            }
        }

        return new SubmissionEnvelope(
            new SubmissionMeta(
                source: self::SOURCE,
                externalId: $externalId,
                instanceId: self::text(FieldMap::get($payload, 'meta.instanceID')) ?? $externalId ?? '',
                deprecatedId: self::text(FieldMap::get($payload, 'meta.deprecatedID')),
                submittedAt: IsoTime::parse(FieldMap::get($payload, '__system.submissionDate')),
                updatedAt: IsoTime::parse(FieldMap::get($payload, '__system.updatedAt')),
                formVersion: self::text(FieldMap::get($payload, '__system.formVersion')) ?? '',
                submitter: self::text(FieldMap::get($payload, '__system.submitterName')) ?? self::text(FieldMap::get($payload, '__system.submitterId')),
                deviceId: self::text(FieldMap::get($payload, '__system.deviceId')),
            ),
            $data,
        );
    }

    /** A scalar as text, as sent; null for null, a group or a list. */
    private static function text(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }
}
