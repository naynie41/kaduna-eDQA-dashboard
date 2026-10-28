<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Enums\AttachmentStatus;
use App\Domain\Ingestion\Jobs\FetchAttachmentsJob;
use App\Domain\Ingestion\Models\SubmissionAttachment;
use Illuminate\Console\Command;

/**
 * Catches up on attachments (ARCHITECTURE.md §5.3): queues FetchAttachmentsJob, on the low queue,
 * for the submissions with pending or failed files, oldest first, at most --limit of them. After
 * a backfill (which only records attachments) run it until it finds nothing; --sync fetches here
 * instead of queueing.
 */
final class FetchPendingAttachments extends Command
{
    protected $signature = 'edqa:attachments:fetch-pending
        {--limit=500 : The most submissions to fetch for}
        {--sync : Fetch now, in this process, instead of queueing}';

    protected $description = 'Fetch the ODK attachments still pending or failed';

    public function handle(): int
    {
        $submissions = SubmissionAttachment::query()
            ->whereIn('status', [AttachmentStatus::Pending, AttachmentStatus::Failed])
            ->orderBy('submission_id')
            ->distinct()
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('submission_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $sync = (bool) $this->option('sync');
        $failed = 0;
        foreach ($submissions as $submissionId) {
            if ($sync) {
                try {
                    FetchAttachmentsJob::dispatchSync($submissionId);
                } catch (\Throwable) {
                    $failed++;
                }
            } else {
                FetchAttachmentsJob::dispatch($submissionId);
            }
        }

        $this->info((string) __($sync ? 'odk.attachments.fetched' : 'odk.attachments.queued', ['count' => count($submissions), 'failed' => $failed]));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
