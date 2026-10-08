<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * The one place submitted text is cleaned before it is compared: trimming, case, spacing,
 * and the "is this really a value?" checks the validation rules share.
 */
final class Normalise
{
    /** Trimmed, or null when blank, whitespace or the literal word "null" (any case). */
    public static function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }

    /** Trimmed, with runs of whitespace collapsed to one space. */
    public static function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** For comparing names (assessors): case and spacing ignored. */
    public static function name(string $value): string
    {
        return mb_strtolower(self::collapse($value));
    }

    /**
     * For matching a reference against a master list by code or name: case, spacing and
     * punctuation ignored ("Birnin-Gwari" = "birnin gwari"). Null when nothing is left.
     */
    public static function matchKey(?string $value): ?string
    {
        $value = self::nullIfBlank($value);
        if ($value === null) {
            return null;
        }

        $key = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)));

        return $key === '' ? null : $key;
    }

    /** Purely a number ("2019.00", "12", "1,5"), ignoring surrounding spaces. */
    public static function isNumeric(string $value): bool
    {
        return preg_match('/^[+-]?\d+(?:[.,]\d+)?$/', trim($value)) === 1;
    }

    /**
     * An ISO 8601 date or date-time that exists on the calendar (ODK sends
     * "2026-04-14T09:00:00.000+01:00"). "FEBRUARY", "14/04/2026" and 30 February are not.
     */
    public static function isIsoDate(string $value): bool
    {
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';
        if (preg_match($pattern, trim($value), $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) ($m[4] ?? 0) < 24
            && (int) ($m[5] ?? 0) < 60
            && (int) ($m[6] ?? 0) < 60;
    }
}
