<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Listeners;

use App\Domain\Assessment\Events\AssessmentStatusChanged;
use App\Domain\Reporting\Jobs\RefreshRoundAggregatesJob;

/** A withdrawn or restored assessment changes its round's figures without a new score: refresh the view (debounced). */
final class RefreshAggregatesAfterStatusChange
{
    public function handle(AssessmentStatusChanged $event): void
    {
        RefreshRoundAggregatesJob::debounced($event->roundId);
    }
}
