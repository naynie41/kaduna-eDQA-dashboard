<?php

declare(strict_types=1);

namespace App\Domain\Round\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A round was closed and pinned to a rule version (ARCHITECTURE.md §3, D-32). CloseRound has
 * already refreshed round_aggregates; listeners are for what follows a close (digest, snapshot
 * exports). Dispatched after the transaction commits.
 */
final readonly class RoundClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $roundId,
        public int $ruleVersionId,
    ) {}
}
