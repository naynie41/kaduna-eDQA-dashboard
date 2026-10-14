<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\DTOs\ScoreValue;

/** One quarter of "Quality score by quarter" (§9.2.1), each under its round's version. */
final readonly class TrendPoint
{
    public function __construct(
        public int $roundId,
        public int $year,
        public int $quarter,
        public string $label,
        public ScoreValue $overall,
        public ScoreValue $public,
        public ScoreValue $private,
    ) {}
}
