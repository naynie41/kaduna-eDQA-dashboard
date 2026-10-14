<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Scoring\DTOs\ScoreValue;

/** Coverage by level (Monitor §9.2.4): facilities visited at that level and their overall. */
final readonly class LevelCoverage
{
    public function __construct(
        public FacilityLevel $level,
        public int $facilities,
        public ScoreValue $overall,
    ) {}
}
