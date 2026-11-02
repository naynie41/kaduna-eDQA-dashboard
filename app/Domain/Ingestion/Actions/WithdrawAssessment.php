<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Assessment\Events\AssessmentStatusChanged;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Exceptions\AssessmentStatusRefused;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Round\Support\RoundWindowCheck;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\RuleCatalogue;
use App\Domain\Validation\Support\Reason;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * "Withdraw assessment" and "Restore" on the Data issues page's Deleted at source tab (D-24,
 * Phase 5 step 2). Withdrawing an assessment whose submission its source no longer returns sets
 * its status to withdrawn: it counts in no aggregator or round_aggregates, and the facility may be
 * assessed again in the round. The deletion is marked reviewed with it. Restoring puts back what
 * it was: flagged while it carries a soft rule's flag, else accepted.
 *
 * Both reasoned and audited; both refused in a closed round (D-32). Restoring is refused once the
 * facility has a live assessment in the round again. AssessmentStatusChanged, after the commit,
 * flushes the round's cache, refreshes round_aggregates and rescores the round.
 */
final class WithdrawAssessment
{
    public function __construct(
        private readonly RuleCatalogue $rules,
    ) {}

    /** @return array{assessment_id: int, status: string, round_id: int} */
    public function withdraw(int $assessmentId, User $actor, string $reason): array
    {
        $reason = Reason::require($reason);

        return $this->change($assessmentId, function (Assessment $assessment, Round $round) use ($actor, $reason): string {
            if ($assessment->status === 'withdrawn') {
                throw AssessmentStatusRefused::alreadyWithdrawn();
            }
            $submission = Submission::query()->lockForUpdate()->findOrFail($assessment->submission_id);
            if ($submission->deleted_at_source === null) {
                throw AssessmentStatusRefused::notDeletedAtSource();
            }

            $old = $assessment->status;
            activity()->withoutLogs(fn () => $assessment->update(['status' => 'withdrawn']));
            if ($submission->deletion_reviewed_at === null) {
                activity()->withoutLogs(fn () => $submission->update(['deletion_reviewed_at' => now(), 'deletion_reviewed_by' => $actor->id]));
            }
            AuditLog::record($assessment, $actor, 'assessment_withdrawn',
                old: ['status' => $old],
                new: ['status' => 'withdrawn'],
                reason: $reason,
                extra: ['submission_id' => $submission->id, 'instance_id' => $submission->instance_id, 'round' => RoundWindowCheck::label($round->year, $round->quarter)],
            );

            return 'withdrawn';
        });
    }

    /** @return array{assessment_id: int, status: string, round_id: int} */
    public function restore(int $assessmentId, User $actor, string $reason): array
    {
        $reason = Reason::require($reason);

        return $this->change($assessmentId, function (Assessment $assessment, Round $round) use ($actor, $reason): string {
            if ($assessment->status !== 'withdrawn') {
                throw AssessmentStatusRefused::notWithdrawn();
            }
            $taken = Assessment::query()->where('round_id', $assessment->round_id)->where('facility_id', $assessment->facility_id)
                ->where('id', '!=', $assessment->id)->where('status', '!=', 'withdrawn')->exists();
            if ($taken) {
                throw AssessmentStatusRefused::roundTaken(RoundWindowCheck::label($round->year, $round->quarter));
            }

            /** @var list<string> $flags */
            $flags = $assessment->flags;
            $status = array_intersect($flags, $this->rules->codes(Severity::Soft)) === [] ? 'accepted' : 'flagged';
            activity()->withoutLogs(fn () => $assessment->update(['status' => $status]));
            AuditLog::record($assessment, $actor, 'assessment_restored',
                old: ['status' => 'withdrawn'],
                new: ['status' => $status],
                reason: $reason,
                extra: ['round' => RoundWindowCheck::label($round->year, $round->quarter)],
            );

            return $status;
        });
    }

    /**
     * @param  callable(Assessment, Round): string  $apply  returns the new status
     * @return array{assessment_id: int, status: string, round_id: int}
     */
    private function change(int $assessmentId, callable $apply): array
    {
        $result = DB::transaction(function () use ($assessmentId, $apply): array {
            $assessment = Assessment::query()->lockForUpdate()->findOrFail($assessmentId);
            $round = Round::query()->findOrFail($assessment->round_id);
            if ($round->status === RoundStatus::Closed) {
                throw AssessmentStatusRefused::roundClosed(RoundWindowCheck::label($round->year, $round->quarter));
            }

            return ['assessment_id' => $assessment->id, 'status' => $apply($assessment, $round), 'round_id' => $round->id];
        });

        AssessmentStatusChanged::dispatch($result['assessment_id'], $result['round_id'], $result['status']);

        return $result;
    }
}
