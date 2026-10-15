<?php

declare(strict_types=1);

namespace App\Domain\Round\Actions;

use App\Domain\Round\DTOs\ReopenResult;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Exceptions\RoundActionRefused;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Actions\RescoreRound;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * "Reopen round" (ARCHITECTURE.md §3; SECURITY.md §4: mandatory reason): the round is open again
 * and takes submissions in its window. rule_version_id is left as it is until the next close
 * re-pins it.
 *
 * An open round reads the current published version (D-32). Asked for, or whenever the pinned
 * version is no longer current (the round would otherwise read a version it has no scores under
 * and show no data), the round is rescored at once under the current version (Q-29).
 */
final class ReopenRound
{
    public const MIN_REASON = 10;

    public function __construct(
        private readonly CurrentRuleVersion $current,
        private readonly RescoreRound $rescore,
    ) {}

    public function execute(Round $round, User $actor, string $reason, bool $rescore = false): ReopenResult
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON) {
            throw RoundActionRefused::reasonTooShort();
        }

        [$round, $rescoring] = DB::transaction(function () use ($round, $actor, $reason, $rescore): array {
            $round = Round::query()->lockForUpdate()->findOrFail($round->id);
            if ($round->status !== RoundStatus::Closed) {
                throw RoundActionRefused::notClosed();
            }

            $rescoring = $rescore || $round->rule_version_id !== $this->current->id();
            $old = ['status' => $round->status->value, 'closed_at' => $round->closed_at?->toIso8601String(), 'reopened_reason' => $round->reopened_reason];
            activity()->withoutLogs(fn () => $round->update(['status' => RoundStatus::Open, 'closed_at' => null, 'reopened_reason' => $reason]));

            AuditLog::record($round, $actor, 'reopened',
                old: $old,
                new: ['status' => RoundStatus::Open->value, 'closed_at' => null, 'reopened_reason' => $reason, 'rescore' => $rescoring],
                reason: $reason,
            );

            return [$round, $rescoring];
        });

        $run = $rescoring ? $this->rescore->start($round, RescoreTrigger::Manual, $actor, $reason) : null;

        return new ReopenResult($round->refresh(), $run);
    }
}
