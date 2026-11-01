<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Events\DeletionReviewed;
use App\Domain\Ingestion\Exceptions\DeletionReviewRefused;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Validation\Support\Reason;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * "Mark reviewed" on a deletion at source (D-62, ARCHITECTURE.md §9.3). Reconcile flags a
 * submission its source no longer returns; nothing is removed from the portal. An administrator
 * looks into it and records that they have, with a reason: who and when are kept on the
 * submission, the reason in the audit log, and DeletionReviewed takes it out of the open issues
 * on the Data issues badge.
 *
 * Refused for a submission still at its source and for one already reviewed. One transaction, the
 * row locked; the event is sent after the commit.
 */
final class ReviewSourceDeletion
{
    public function __construct(
        private readonly SourceRegistry $sources,
    ) {}

    /**
     * @return array{submission_id: int, reviewed_at: ?string}
     *
     * @throws ModelNotFoundException<Submission> no such submission (404)
     */
    public function execute(int $submissionId, User $actor, string $reason): array
    {
        $reason = Reason::require($reason);

        $submission = DB::transaction(function () use ($submissionId, $actor, $reason): Submission {
            $row = Submission::query()->lockForUpdate()->findOrFail($submissionId);
            if ($row->deleted_at_source === null) {
                throw DeletionReviewRefused::notDeleted();
            }
            if ($row->deletion_reviewed_at !== null) {
                throw DeletionReviewRefused::alreadyReviewed();
            }

            $now = now();
            activity()->withoutLogs(fn () => $row->update(['deletion_reviewed_at' => $now, 'deletion_reviewed_by' => $actor->id]));
            AuditLog::record($row, $actor, 'deletion_reviewed',
                old: ['deletion_reviewed_at' => null],
                new: ['deletion_reviewed_at' => $now->toIso8601String(), 'deletion_reviewed_by' => $actor->id],
                reason: $reason,
            );

            return $row->refresh();
        });

        DeletionReviewed::dispatch($submission->id, (string) $this->sources->keyOf($submission->source_id));

        return ['submission_id' => $submission->id, 'reviewed_at' => $submission->deletion_reviewed_at?->toIso8601String()];
    }
}
