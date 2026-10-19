<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Attachments\AttachmentRules;
use App\Domain\Ingestion\DTOs\AttachmentRef;
use App\Domain\Ingestion\Enums\AttachmentStatus;
use Illuminate\Support\Facades\DB;

/**
 * Records the attachments ODK expects for new submissions, pending, in one statement; a row
 * already recorded is left alone (ARCHITECTURE.md §5.3). Fetching is separate: the pull
 * dispatches FetchAttachmentsJob, a backfill (2B.6) only records.
 */
final class RecordAttachments
{
    /**
     * @param  array<int, list<AttachmentRef>>  $bySubmission  submission ID => what ODK lists
     * @return list<int> the submissions that gained a pending row
     */
    public function execute(array $bySubmission): array
    {
        $rows = [];
        foreach ($bySubmission as $submissionId => $refs) {
            foreach ($refs as $ref) {
                $rows[] = ['submission_id' => $submissionId, 'filename' => AttachmentRules::storable($ref->name), 'status' => AttachmentStatus::Pending->value];
            }
        }
        if ($rows === []) {
            return [];
        }

        DB::table('odk_attachments')->insertOrIgnore($rows);

        return array_values(array_unique(array_map(fn (array $row): int => $row['submission_id'], $rows)));
    }
}
