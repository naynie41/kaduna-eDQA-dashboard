<?php

declare(strict_types=1);

namespace App\Domain\Round\Actions;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Reporting\Actions\RefreshRoundAggregates;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Events\RoundClosed;
use App\Domain\Round\Exceptions\RoundActionRefused;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\RuleVersionForRound;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * "Close round" (ARCHITECTURE.md §3, D-32): pins the version the round is scored under (the
 * current published one, as it is open), then refreshes round_aggregates so the closed round's
 * figures are a snapshot, and dispatches RoundClosed. From now on ROUND_WINDOW quarantines its
 * submissions ("round closed") and rescore refuses it.
 *
 * Refused while any accepted or flagged assessment has no scores under that version (a rescore
 * still running, or one that could not score it): a round is never pinned to scores it lacks.
 */
final class CloseRound
{
    public function __construct(
        private readonly RuleVersionForRound $versions,
        private readonly RefreshRoundAggregates $refresh,
    ) {}

    public function execute(Round $round, User $actor): Round
    {
        $round = DB::transaction(function () use ($round, $actor): Round {
            $round = Round::query()->lockForUpdate()->findOrFail($round->id);
            if ($round->status === RoundStatus::Closed) {
                throw RoundActionRefused::alreadyClosed();
            }

            $versionId = $this->versions->id($round);
            $unscored = Assessment::query()
                ->where('round_id', $round->id)
                ->whereIn('status', ['accepted', 'flagged'])
                ->whereDoesntHave('scores', fn ($q) => $q->where('rule_version_id', $versionId))
                ->pluck('id');
            if ($unscored->isNotEmpty()) {
                throw RoundActionRefused::notFullyScored($unscored->count());
            }

            $old = ['status' => $round->status->value, 'rule_version_id' => $round->rule_version_id, 'closed_at' => $round->closed_at?->toIso8601String()];
            $closedAt = now();
            activity()->withoutLogs(fn () => $round->update(['status' => RoundStatus::Closed, 'rule_version_id' => $versionId, 'closed_at' => $closedAt]));

            AuditLog::record($round, $actor, 'closed',
                old: $old,
                new: ['status' => RoundStatus::Closed->value, 'rule_version_id' => $versionId, 'closed_at' => $closedAt->toIso8601String()],
            );

            RoundClosed::dispatch($round->id, $versionId);

            return $round;
        });

        // The snapshot, once the close is committed.
        $this->refresh->execute($round->id);

        return $round;
    }
}
