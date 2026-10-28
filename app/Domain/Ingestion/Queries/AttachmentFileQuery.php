<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\Attachments\AttachmentRules;
use App\Domain\Ingestion\Enums\AttachmentStatus;
use App\Domain\Ingestion\Models\SubmissionAttachment;

/**
 * The one fetched attachment a request may read (SECURITY.md §7): the filename must be a safe
 * name recorded, exactly, for that submission, and its file fetched and on the disk. Anything
 * else is null and the route answers 404. The path served is the one stored on the row, never
 * built from the request.
 */
final class AttachmentFileQuery
{
    public function find(int $submissionId, string $filename): ?SubmissionAttachment
    {
        if (! AttachmentRules::isSafeName($filename)) {
            return null;
        }

        $attachment = SubmissionAttachment::query()
            ->where('submission_id', $submissionId)
            ->where('filename', $filename)
            ->where('status', AttachmentStatus::Fetched)
            ->whereNotNull('path')
            ->first();

        return $attachment;
    }
}
