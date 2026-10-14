<?php

declare(strict_types=1);

namespace App\Domain\Validation\Actions;

use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * "Fix at source" (ARCHITECTURE.md §6): the assessor edits the submission in ODK Central; the
 * next pull brings a new instance whose deprecatedID names the quarantined one. Every open
 * record for that instance is resolved as superseded, automatically, with no person as the
 * actor, and its submission marked superseded by the new one. Called by the 2B pull.
 */
final class MarkSuperseded
{
    /** @return list<QuarantinedRecord> the records resolved */
    public function execute(Submission $newer): array
    {
        if ($newer->deprecated_id === null) {
            return [];
        }

        return DB::transaction(function () use ($newer): array {
            $records = QuarantinedRecord::query()->with('submission')
                ->where('instance_id', $newer->deprecated_id)
                ->where('status', QuarantineStatus::Open)
                ->lockForUpdate()
                ->get();

            $note = __('quarantine.superseded_note', ['instance' => $newer->instance_id]);
            $note = is_string($note) ? $note : $newer->instance_id;

            foreach ($records as $record) {
                activity()->withoutLogs(fn () => $record->update([
                    'status' => QuarantineStatus::Resolved,
                    'resolution' => QuarantineResolution::Superseded,
                    'resolved_at' => now(),
                    'resolution_note' => $note,
                ]));
                $record->submission->update(['status' => SubmissionStatus::Superseded, 'superseded_by_id' => $newer->id]);

                AuditLog::record($record, null, 'superseded',
                    old: ['status' => QuarantineStatus::Open->value, 'resolution' => null],
                    new: ['status' => QuarantineStatus::Resolved->value, 'resolution' => QuarantineResolution::Superseded->value],
                    reason: $note,
                    extra: ['superseded_by' => $newer->instance_id],
                );

                QuarantineResolved::dispatch($record->id, $record->round_id, QuarantineResolution::Superseded);
            }

            return array_values($records->all());
        });
    }
}
