<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\DTOs\ScoreValue;

/** One group's scores (an owner type): its facility count, three dimensions and overall. */
final readonly class GroupScores
{
    public function __construct(
        public int $facilities,
        public ScoreValue $availability,
        public ScoreValue $consistency,
        public ScoreValue $validity,
        public ScoreValue $overall,
    ) {}
}
