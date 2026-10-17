<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\Enums\Dimension;

/** "Actions by type" and "What each action means" (§9.2.6): one per dimension, in fixed order. */
final readonly class PlanSummary
{
    public function __construct(
        public Dimension $dimension,
        public string $action,
        public string $meaning,
        public string $owner,
        public int $count,
    ) {}
}
