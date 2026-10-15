<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\DTOs\ScoreValue;

/**
 * Home hero (§9.2.1): the average and the four minis. Strong and weak count as displayed, against
 * the rule version's thresholds, sent with them (strongAt, weakBelow; null when no version).
 */
final readonly class StateSummary
{
    public function __construct(
        public ScoreValue $overall,
        public int $facilitiesVisited,
        public int $lgasCovered,
        public int $facilitiesStrong,
        public int $facilitiesWeak,
        public ?float $strongAt = null,
        public ?float $weakBelow = null,
    ) {}
}
