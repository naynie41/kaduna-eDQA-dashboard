<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Attachments\AttachmentRules;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Enums\AttachmentStatus;
use App\Domain\Ingestion\Exceptions\OdkException;
use App\Domain\Ingestion\Models\OdkAttachment;
use App\Domain\Ingestion\Models\Submission;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fetches one submission's pending (or earlier failed) attachments from ODK to the private odk
 * disk (ARCHITECTURE.md §5.3, SECURITY.md §7). A fetched file is never fetched again.
 *
 * Each file is downloaded to odk/.incoming first, then checked: its size against
 * edqa.odk.attachments.max_bytes and its sniffed type against the allow-list. A file that fails
 * is deleted and its row marked rejected with the reason; one that passes moves to
 * odk/{instance}/{filename}. A name that could leave its directory is rejected without a download.
 *
 * ODK errors leave the row pending with the reason and are rethrown once every file has been
 * tried, so the job retries only what is still missing.
 */
final class FetchAttachments
{
    public const INCOMING = '.incoming';

    public function __construct(
        private readonly SubmissionSource $source,
    ) {}

    /** @return array{fetched: int, rejected: int, pending: int} */
    public function execute(int $submissionId): array
    {
        $counts = ['fetched' => 0, 'rejected' => 0, 'pending' => 0];
        $submission = Submission::query()->find($submissionId);
        if ($submission === null) {
            return $counts;
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('edqa.odk.attachments.disk', 'odk'));
        $maxBytes = (int) config('edqa.odk.attachments.max_bytes', 10 * 1024 * 1024);
        /** @var list<string> $allowed */
        $allowed = array_values((array) config('edqa.odk.attachments.allowed_mimes', []));
        // Central lists a submission's files under its root ID (__id), the current version's files.
        $rootId = is_string($submission->payload['__id'] ?? null) ? $submission->payload['__id'] : $submission->instance_id;
        $directory = AttachmentRules::directory((string) $submission->instance_id);

        $error = null;
        $rows = OdkAttachment::query()->where('submission_id', $submissionId)
            ->whereIn('status', [AttachmentStatus::Pending, AttachmentStatus::Failed])->orderBy('id')->get();
        foreach ($rows as $attachment) {
            $name = (string) $attachment->filename;
            if (! AttachmentRules::isSafeName($name)) {
                $this->reject($attachment, 'unsafe_name');
                $counts['rejected']++;

                continue;
            }

            $target = "{$directory}/{$name}";
            $incoming = self::INCOMING.'/'.Str::uuid()->toString();
            if (! $disk->exists($target)) {
                $disk->makeDirectory(self::INCOMING);
                try {
                    $this->source->downloadAttachment($rootId, $name, $disk->path($incoming));
                } catch (OdkException $e) {
                    $disk->delete($incoming);
                    $attachment->update(['reason' => $e->status()]);
                    $error = $e;
                    $counts['pending']++;

                    continue;
                }
            } else {
                // Stored by an attempt that stopped before recording it: check it, do not fetch again.
                $disk->move($target, $incoming);
            }

            $outcome = $this->check($disk, $incoming, $name, $maxBytes, $allowed);
            if (is_string($outcome)) {
                $disk->delete($incoming);
                $this->reject($attachment, $outcome);
                $counts['rejected']++;

                continue;
            }

            $disk->makeDirectory($directory);
            $disk->move($incoming, $target);
            $attachment->update([
                'status' => AttachmentStatus::Fetched, 'mime' => $outcome['mime'], 'size' => $outcome['size'],
                'path' => $target, 'fetched_at' => now(), 'reason' => null,
            ]);
            $counts['fetched']++;
        }

        if ($error !== null) {
            throw $error;
        }

        return $counts;
    }

    /**
     * @param  list<string>  $allowed
     * @return array{mime: string, size: int}|string the type and size, or why it is refused
     */
    private function check(FilesystemAdapter $disk, string $path, string $name, int $maxBytes, array $allowed): array|string
    {
        $size = $disk->size($path);
        if ($size > $maxBytes) {
            return 'too_large';
        }
        $mime = AttachmentRules::sniff($disk->path($path), $name, $allowed);

        return $mime === null ? 'type_not_allowed' : ['mime' => $mime, 'size' => $size];
    }

    private function reject(OdkAttachment $attachment, string $reason): void
    {
        $attachment->update(['status' => AttachmentStatus::Rejected, 'reason' => $reason, 'path' => null, 'fetched_at' => null]);
    }
}
