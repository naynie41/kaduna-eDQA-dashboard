<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Listeners;

use App\Domain\Assessment\Events\AssessmentCorrected;
use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Domain\Scoring\Jobs\RequestRescoreJob;

/** A corrected assessment changes its round's scores and score-based flags: rescore the round (debounced). */
final class RescoreAfterAssessmentCorrected
{
    public function handle(AssessmentCorrected $event): void
    {
        RequestRescoreJob::debounced($event->roundId, RescoreTrigger::Correction);
    }
}
