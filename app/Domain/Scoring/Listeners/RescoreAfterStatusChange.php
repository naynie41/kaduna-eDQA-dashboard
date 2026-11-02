<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Listeners;

use App\Domain\Assessment\Events\AssessmentStatusChanged;
use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Domain\Scoring\Jobs\RequestRescoreJob;

/** A withdrawn or restored assessment leaves or rejoins its round: rescore the round (debounced). */
final class RescoreAfterStatusChange
{
    public function handle(AssessmentStatusChanged $event): void
    {
        RequestRescoreJob::debounced($event->roundId, RescoreTrigger::Correction);
    }
}
