<?php

declare(strict_types=1);

namespace App\Domain\Round\Support;

/** The one way to name a round: "Q2 2026" (reporting.round_label). */
final class RoundLabel
{
    public static function of(int $year, int $quarter): string
    {
        return (string) __('reporting.round_label', ['quarter' => (string) $quarter, 'year' => (string) $year]);
    }
}
