<?php

declare(strict_types=1);

namespace App\Domain\Scoring;

use InvalidArgumentException;

/**
 * The one place a score is rounded for display (CONVENTION.md §2.7). Scores are stored and
 * computed at full precision; only what a person reads is rounded, in the mode set by
 * edqa.presentation.rounding (Q-09): 'half_up' (default) or 'truncate'.
 */
final class Presentation
{
    public const MODES = ['half_up', 'truncate'];

    public static function round(float $value, int $dp): float
    {
        return match (self::mode()) {
            'half_up' => round($value, $dp, PHP_ROUND_HALF_UP),
            'truncate' => self::truncate($value, $dp),
        };
    }

    /** The big figures: a whole number. */
    public static function headline(float $value): int
    {
        return (int) self::round($value, 0);
    }

    private static function truncate(float $value, int $dp): float
    {
        $factor = 10 ** $dp;
        // Settle float noise before cutting: 0.29 × 100 is 28.999…, which must stay 29.
        $scaled = round($value * $factor, 6);

        return ($scaled < 0 ? ceil($scaled) : floor($scaled)) / $factor;
    }

    /**
     * The value as displayed (1 dp by default), in SQL: for aggregators that must count or
     * compare what people see, e.g. "facilities 90+" (Q-26).
     */
    public static function sql(string $expression, int $dp = 1): string
    {
        return self::mode() === 'truncate'
            ? "trunc(({$expression})::numeric, {$dp})"
            : "round(({$expression})::numeric, {$dp})";
    }

    /** @return 'half_up'|'truncate' */
    private static function mode(): string
    {
        $mode = config('edqa.presentation.rounding', 'half_up');

        return match ($mode) {
            'half_up', 'truncate' => $mode,
            default => throw new InvalidArgumentException('edqa.presentation.rounding must be half_up or truncate.'),
        };
    }
}
