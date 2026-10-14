<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Listeners;

use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Events\RuleVersionPublished;
use Illuminate\Support\Facades\Log;

/**
 * PLACEHOLDER: publishing rescores every OPEN round under the new version; closed rounds keep
 * their pinned one (D-32). Until RescoreRoundJob exists the request is logged, so none is lost
 * silently. Until then an open round has no scores under the new version, and its aggregates are
 * empty: do not publish a new version in production before the rescore job lands. When it does,
 * it dispatches RefreshRoundAggregatesJob::debounced() for each round it rescored.
 */
final class RescoreOpenRoundsAfterPublish
{
    public function handle(RuleVersionPublished $event): void
    {
        $openRounds = Round::query()->where('status', RoundStatus::Open)->orderBy('id')->pluck('id')->all();

        Log::channel('edqa')->info('rule_version.published.rescore_pending', [
            'rule_version_id' => $event->ruleVersionId,
            'round_ids' => array_map(intval(...), $openRounds),
        ]);
    }
}
