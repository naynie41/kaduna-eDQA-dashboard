<?php

declare(strict_types=1);

namespace App\Domain\Validation\Actions;

use App\Domain\Ingestion\Actions\ProcessParsedSubmission;
use App\Domain\Ingestion\DTOs\ProcessOutcome;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Support\RoundWindowCheck;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Domain\Validation\Support\Reason;
use App\Domain\Validation\ValidationContextFactory;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * "Use this one instead" on a cross-source duplicate (ARCHITECTURE.md §5.8, Phase 5 step 2). The
 * quarantined submission, held only as DUPLICATE_ASSESSMENT, replaces the facility's accepted
 * assessment in the round: it is reprocessed as an edit of the accepted instance, so the pipeline
 * keeps the same assessment row (its plan actions and links), replaces its items and scores from
 * this submission, and marks the old submission superseded. Nothing is scored here: the pipeline
 * scores it, as every submission (CLAUDE.md hard rule 2).
 *
 * Refused for a record already resolved, for anything but a duplicate, and in a closed round
 * (D-18). If the submission would now fail another rule, nothing changes. One transaction, the
 * record locked; audited with both instance IDs and the reason; QuarantineResolved queues the
 * round's rescore and updates the Data issues badge.
 */
final class ReplaceAssessmentFromSubmission
{
    public function __construct(
        private readonly CorrectAndReprocess $correct,
        private readonly ValidationContextFactory $contexts,
        private readonly ProcessParsedSubmission $process,
    ) {}

    public function execute(QuarantinedRecord $record, User $actor, string $reason): ProcessOutcome
    {
        $reason = Reason::require($reason);

        return DB::transaction(function () use ($record, $actor, $reason): ProcessOutcome {
            $record = QuarantinedRecord::query()->with('submission')->lockForUpdate()->findOrFail($record->id);
            if ($record->status !== QuarantineStatus::Open) {
                throw QuarantineActionRefused::alreadyResolved();
            }
            $hard = array_values(array_unique(array_column(array_filter($record->failures, fn (array $f): bool => ($f['severity'] ?? 'hard') === 'hard'), 'code')));
            if ($hard !== ['DUPLICATE_ASSESSMENT']) {
                throw QuarantineActionRefused::notADuplicate();
            }

            $parsed = $this->correct->parsedFor($record);
            $context = $this->contexts->forBatch([$parsed]);
            $facility = $context->facility($parsed->facilityRef);
            $round = $context->roundFor($parsed);
            if ($facility === null || $round === null) {
                throw QuarantineActionRefused::notADuplicate();
            }
            if ($round->status === RoundStatus::Closed) {
                throw QuarantineActionRefused::roundClosed(RoundWindowCheck::label($round->year, $round->quarter));
            }
            $replaced = $context->duplicateOf($parsed, $round->id, $facility->id) ?? throw QuarantineActionRefused::notADuplicate();

            $outcome = $this->process->execute($record->submission, $parsed->asEditOf($replaced), $context, reprocessing: $record);
            if ($outcome->status === SubmissionStatus::Quarantined) {
                throw QuarantineActionRefused::stillFailing(implode(', ', array_map(fn ($f): string => $f->code, $outcome->result->hardFailures)));
            }

            activity()->withoutLogs(fn () => $record->update([
                'status' => QuarantineStatus::Resolved,
                'resolution' => QuarantineResolution::Reprocessed,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => $reason,
            ]));
            AuditLog::record($record, $actor, 'assessment_replaced',
                old: ['status' => QuarantineStatus::Open->value, 'instance_id' => $replaced],
                new: ['status' => QuarantineStatus::Resolved->value, 'resolution' => QuarantineResolution::Reprocessed->value, 'instance_id' => $parsed->instanceId],
                reason: $reason,
                extra: ['assessment_id' => $outcome->assessmentId, 'replaced_instance_id' => $replaced, 'instance_id' => $parsed->instanceId],
            );

            QuarantineResolved::dispatch($record->id, $record->round_id, QuarantineResolution::Reprocessed);

            return $outcome;
        });
    }
}
