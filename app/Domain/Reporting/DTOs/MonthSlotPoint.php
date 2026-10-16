<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\DTOs\ScoreValue;

/**
 * One quarter × month slot for the Visuals grouped bars (§9.2.5), labelled with the real month:
 * label "April 2026", chartLabel (the x axis) "April (Q2 2026)".
 */
final readonly class MonthSlotPoint
{
    public function __construct(
        public int $roundId,
        public int $year,
        public int $quarter,
        public int $slot,
        public int $month,
        public string $label,
        public string $chartLabel,
        public ScoreValue $availability,
        public ScoreValue $consistency,
        public ScoreValue $validity,
        public ScoreValue $overall,
    ) {}
}
