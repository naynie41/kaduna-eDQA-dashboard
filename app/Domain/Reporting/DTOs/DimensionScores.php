<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Enums\Dimension;

/** "The three dimensions" (§9.2.1), and which is weakest for the note. */
final readonly class DimensionScores
{
    public function __construct(
        public ScoreValue $availability,
        public ScoreValue $consistency,
        public ScoreValue $validity,
        public ScoreValue $overall,
        public ?Dimension $weakest,
    ) {}
}
