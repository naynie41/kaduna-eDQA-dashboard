<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An assessment's scores were written for a rule version (ARCHITECTURE.md §2: listeners will
 * refresh round_aggregates and flush the round's cache). Dispatched after the transaction
 * commits, so a listener never sees scores that were rolled back.
 */
final readonly class AssessmentScored implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $assessmentId,
        public int $roundId,
        public int $ruleVersionId,
    ) {}
}
