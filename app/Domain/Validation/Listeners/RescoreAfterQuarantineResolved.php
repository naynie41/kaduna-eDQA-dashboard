<?php

declare(strict_types=1);

namespace App\Domain\Validation\Listeners;

use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Domain\Scoring\Jobs\RequestRescoreJob;
use App\Domain\Validation\Events\QuarantineResolved;

/**
 * Resolving a quarantined record triggers a rescore of its round (ARCHITECTURE.md §6), debounced
 * so a bulk resolution rescores the round once. A record never placed in a round has none.
 */
final class RescoreAfterQuarantineResolved
{
    public function handle(QuarantineResolved $event): void
    {
        if ($event->roundId !== null) {
            RequestRescoreJob::debounced($event->roundId, RescoreTrigger::Quarantine);
        }
    }
}
