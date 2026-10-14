<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Listeners;

use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Domain\Scoring\Events\RuleVersionPublished;
use App\Domain\Scoring\Jobs\RequestRescoreJob;

/**
 * Publishing rescores every OPEN round under the new version; closed rounds keep their pinned one
 * (D-32). Until an open round's rescore finishes, its aggregates read the new version's rows that
 * exist so far (none at first): the publish preview already showed the effect.
 */
final class RescoreOpenRoundsAfterPublish
{
    public function handle(RuleVersionPublished $event): void
    {
        $openRounds = Round::query()->where('status', RoundStatus::Open)->orderBy('id')->pluck('id');

        foreach ($openRounds as $roundId) {
            RequestRescoreJob::debounced((int) $roundId, RescoreTrigger::Publish);
        }
    }
}
