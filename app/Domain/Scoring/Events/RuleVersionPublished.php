<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A rule version was published (ARCHITECTURE.md §2): open rounds are rescored under it. Closed
 * rounds keep their pinned version (D-32). Dispatched after the transaction commits.
 */
final readonly class RuleVersionPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $ruleVersionId,
    ) {}
}
