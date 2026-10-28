<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Strict ISO 8601 date-times, as every source writes them: a date, a time and an offset (or Z).
 * Anything else ("FEBRUARY", a date alone, a time with no zone) is not read, so validation sees
 * it as unreadable instead of a guess (CLAUDE.md hard rule 12).
 */
final class IsoTime
{
    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})$/';

    public static function parse(mixed $value): ?CarbonImmutable
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
}
