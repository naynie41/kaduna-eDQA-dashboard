<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An assessment was withdrawn or restored (D-62): it leaves or rejoins every figure, so the round's
 * cache is flushed, round_aggregates refreshed and the round rescored.
 */
final readonly class AssessmentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $assessmentId,
        public int $roundId,
        public string $status,
    ) {}
}
