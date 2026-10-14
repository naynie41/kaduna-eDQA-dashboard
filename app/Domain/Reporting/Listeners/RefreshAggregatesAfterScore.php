<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Listeners;

use App\Domain\Assessment\Events\AssessmentScored;
use App\Domain\Reporting\Jobs\RefreshRoundAggregatesJob;

/**
 * A scored assessment changes its round's aggregates: queue the debounced refresh of
 * round_aggregates (one per round per minute, however many scores arrive).
 */
final class RefreshAggregatesAfterScore
{
    public function handle(AssessmentScored $event): void
    {
        RefreshRoundAggregatesJob::debounced($event->roundId);
    }
}
