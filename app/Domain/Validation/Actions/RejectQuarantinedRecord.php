<?php

declare(strict_types=1);

namespace App\Domain\Validation\Actions;

use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Domain\Validation\Support\Reason;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * "Reject" (ARCHITECTURE.md §6): the submission is excluded permanently, with a typed reason.
 * The record is resolved as rejected (status resolved, resolution rejected; the schema has no
 * separate rejected status) and its submission marked rejected. A resolved record cannot be
 * reopened or corrected.
 */
final class RejectQuarantinedRecord
{
    public function execute(QuarantinedRecord $record, User $actor, string $reason): QuarantinedRecord
    {
        $reason = Reason::require($reason);

        return DB::transaction(function () use ($record, $actor, $reason): QuarantinedRecord {
            $record = QuarantinedRecord::query()->with('submission')->lockForUpdate()->findOrFail($record->id);
            if ($record->status !== QuarantineStatus::Open) {
                throw QuarantineActionRefused::alreadyResolved();
            }

            activity()->withoutLogs(fn () => $record->update([
                'status' => QuarantineStatus::Resolved,
                'resolution' => QuarantineResolution::Rejected,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => $reason,
            ]));
            $record->submission->update(['status' => SubmissionStatus::Rejected]);

            AuditLog::record($record, $actor, 'rejected',
                old: ['status' => QuarantineStatus::Open->value, 'resolution' => null],
                new: ['status' => QuarantineStatus::Resolved->value, 'resolution' => QuarantineResolution::Rejected->value],
                reason: $reason,
            );

            QuarantineResolved::dispatch($record->id, $record->round_id, QuarantineResolution::Rejected);

            return $record;
        });
    }
}
