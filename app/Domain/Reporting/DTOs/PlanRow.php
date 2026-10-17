<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Enums\Dimension;

/**
 * One implementation-plan action (§9.2.6): an LGA, the dimension acted on (its weakest, or the
 * stored row's), that dimension's score, the action and owner, and when to review it: a stored
 * row's date, else "after" the latest round in view. stored marks a plan_actions row.
 */
final readonly class PlanRow
{
    public function __construct(
        public int $lgaId,
        public string $code,
        public string $name,
        public ScoreValue $overall,
        public Dimension $dimension,
        public ScoreValue $score,
        public string $action,
        public string $owner,
        public ?string $reviewDate,
        public ?string $reviewRound,
        public bool $stored,
    ) {}
}
