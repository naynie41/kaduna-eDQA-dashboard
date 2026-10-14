<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An administrator corrected an accepted assessment's item responses (Phase 5 corrections): its
 * round is rescored (ARCHITECTURE.md §7). Dispatched after the transaction commits.
 */
final readonly class AssessmentCorrected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $assessmentId,
        public int $roundId,
    ) {}
}
