<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

use App\Domain\Ingestion\FieldMap;
use Carbon\CarbonImmutable;
use Throwable;

/** Reading the few fields of a raw OData entry that the pull itself needs, before any parsing. */
final class OdkRow
{
    /**
     * The version's own instance ID (meta.instanceID; __id when absent).
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function instanceId(array $row): string
    {
        return self::text(FieldMap::get($row, 'meta.instanceID')) ?? self::text($row['__id'] ?? null) ?? '';
    }

    /**
     * The submission's root ID (__id), under which Central lists its attachments.
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function rootId(array $row): string
    {
        return self::text($row['__id'] ?? null) ?? self::instanceId($row);
    }

    public static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
