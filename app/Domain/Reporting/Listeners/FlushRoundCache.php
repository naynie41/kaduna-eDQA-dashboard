<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Listeners;

use App\Domain\Assessment\Events\AssessmentScored;
use App\Domain\Reporting\RoundCache;
use App\Domain\Scoring\Events\RuleVersionPublished;
use App\Domain\Validation\Events\QuarantineResolved;

/**
 * Keeps cached aggregates true (ARCHITECTURE.md §2): a round's entries go when one of its
 * assessments is scored or a quarantined record in it is resolved; everything goes when a rule
 * version is published (open rounds change version) or a round itself changes.
 */
final class FlushRoundCache
{
    public function __construct(
        private readonly RoundCache $cache,
    ) {}

    public function onScored(AssessmentScored $event): void
    {
        $this->cache->flushRound($event->roundId);
    }

    public function onResolved(QuarantineResolved $event): void
    {
        if ($event->roundId !== null) {
            $this->cache->flushRound($event->roundId);
        }
    }

    public function onPublished(RuleVersionPublished $event): void
    {
        $this->cache->flushAll();
    }
}
