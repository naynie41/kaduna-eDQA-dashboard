<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\FieldMap;

/**
 * The ODK Central OData entry that SubmissionParser reads back into a given ParsedSubmission: the
 * inverse of the parser, through the submission's own form version's FieldMap. Lets a test built
 * with ParsedSubmissionBuilder store a realistic raw payload, so anything that reparses
 * submissions.payload (CorrectAndReprocess, the pull) sees the same submission.
 *
 * Answers use the default choice map (yes / no / na); an 'invalid' item becomes a choice no map
 * knows. Scored questions sit at the top level in item order, so they parse back in that order.
 */
final class OdkPayload
{
    public const CHOICES = ['pass' => 'yes', 'fail' => 'no', 'na' => 'na', ItemResponseData::INVALID => 'not-a-choice'];

    private const PREFIXES = ['availability' => 'avail', 'consistency' => 'consist', 'validity' => 'valid'];

    /** @return array<string, mixed> */
    public static function fromParsed(ParsedSubmission $parsed): array
    {
        $entry = [
            '__id' => $parsed->deprecatedId === null ? $parsed->instanceId : 'uuid:root-'.md5($parsed->instanceId),
            '__system' => [
                'submissionDate' => $parsed->submittedAt->toIso8601String(),
                'updatedAt' => $parsed->deprecatedId === null ? null : $parsed->submittedAt->toIso8601String(),
                'deviceId' => $parsed->deviceId,
                'edits' => $parsed->deprecatedId === null ? 0 : 1,
                'formVersion' => $parsed->formVersion,
            ],
            'meta' => array_filter(['instanceID' => $parsed->instanceId, 'deprecatedID' => $parsed->deprecatedId], fn (?string $v): bool => $v !== null),
        ];

        $map = FieldMap::for($parsed->source, $parsed->formVersion);
        if (! $parsed->formVersionKnown || $map === null) {
            return $entry;
        }

        $fields = [
            'started_at' => $parsed->startedAt?->format('Y-m-d\TH:i:s.vP') ?? $parsed->rawStart,
            'ended_at' => $parsed->endedAt?->format('Y-m-d\TH:i:s.vP') ?? $parsed->rawEnd,
            'lga_code' => $parsed->lgaRef,
            'ward_code' => $parsed->wardRef,
            'facility_code' => $parsed->facilityRef,
            'round_year' => $parsed->roundYear,
            'round_quarter' => $parsed->roundQuarter,
            'assessor' => $parsed->assessorName,
            'gps' => $parsed->gps === null ? null : array_filter([
                'type' => 'Point',
                'coordinates' => [$parsed->gps['lng'] ?? 0.0, $parsed->gps['lat'] ?? 0.0],
                'properties' => isset($parsed->gps['accuracy']) ? ['accuracy' => $parsed->gps['accuracy']] : null,
            ], fn (mixed $v): bool => $v !== null),
        ];
        foreach ($fields as $field => $value) {
            $path = $map->path($field);
            if ($path !== null) {
                $entry = self::put($entry, $path, $value);
            }
        }

        foreach ($parsed->items as $item) {
            $canonical = self::PREFIXES[$item->dimension->value].'_m'.$item->monthSlot.'_'.$item->itemCode;
            $entry[$map->questionName($canonical)] = self::CHOICES[$item->value] ?? self::CHOICES[ItemResponseData::INVALID];
        }
        foreach ($parsed->rawNumericScores ?? [] as $canonical => $score) {
            $entry[$map->questionName($canonical)] = $score;
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function put(array $entry, string $path, mixed $value): array
    {
        $steps = explode('.', $path);
        $ref = &$entry;
        foreach ($steps as $i => $step) {
            if ($i === count($steps) - 1) {
                $ref[$step] = $value;
            } else {
                if (! isset($ref[$step]) || ! is_array($ref[$step])) {
                    $ref[$step] = [];
                }
                $ref = &$ref[$step];
            }
        }

        return $entry;
    }
}
