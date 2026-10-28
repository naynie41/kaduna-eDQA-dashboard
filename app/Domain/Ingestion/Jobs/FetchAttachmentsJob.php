<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Jobs;

use App\Domain\Ingestion\Actions\FetchAttachments;
use App\Domain\Ingestion\Enums\AttachmentStatus;
use App\Domain\Ingestion\Models\SubmissionAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Fetches one submission's attachments (FetchAttachments), on the low queue so it never delays a
 * pull. Dispatched by the pull for each new submission that expects files, and by
 * edqa:attachments:fetch-pending to catch up after a backfill. After the last try, what is still
 * pending is marked failed; fetch-pending tries failed files again.
 */
final class FetchAttachmentsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 300;

    public function __construct(
        public readonly int $submissionId,
    ) {
        $this->onQueue('low');
    }

    public function handle(FetchAttachments $fetch): void
    {
        $fetch->execute($this->submissionId);
    }

    public function failed(?Throwable $e): void
    {
        SubmissionAttachment::query()
            ->where('submission_id', $this->submissionId)
            ->where('status', AttachmentStatus::Pending)
            ->update(['status' => AttachmentStatus::Failed]);
    }
}
