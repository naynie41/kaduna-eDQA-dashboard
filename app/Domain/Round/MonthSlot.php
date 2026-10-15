<?php

declare(strict_types=1);

namespace App\Domain\Round;

use InvalidArgumentException;

/**
 * Month slots (ARCHITECTURE.md §3): slot 1–3 of quarter 1–4 is calendar month
 * (quarter − 1) × 3 + slot, so Q2 slot 1 is April. Mirrored by resources/js/lib/months.ts;
 * both are tested against the same table.
 */
final class MonthSlot
{
    public static function month(int $quarter, int $slot): int
    {
        if ($quarter < 1 || $quarter > 4) {
            throw new InvalidArgumentException("A quarter is 1 to 4, got {$quarter}.");
        }
        if ($slot < 1 || $slot > 3) {
            throw new InvalidArgumentException("A month slot is 1 to 3, got {$slot}.");
        }

        return ($quarter - 1) * 3 + $slot;
    }

    /** "April 2026": the slot's real month, for when one quarter is in view. */
    public static function label(int $year, int $quarter, int $slot): string
    {
        $month = self::month($quarter, $slot);

        return (string) __('reporting.month_label', ['month' => (string) __("reporting.months.{$month}"), 'year' => (string) $year]);
    }
}
