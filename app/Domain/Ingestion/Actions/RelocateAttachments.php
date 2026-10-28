<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Attachments\AttachmentRules;
use App\Domain\Ingestion\Enums\AttachmentStatus;
use App\Domain\Ingestion\Models\SubmissionAttachment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * One-off, for Phase 2C (ARCHITECTURE.md §5.3): moves fetched attachments from the old odk disk
 * (storage/app/odk/{instance}/{file}) to the attachments disk under their source
 * (storage/app/attachments/{source}/{instance}/{file}), and points each row at its new path.
 * Idempotent: a row already under its source's directory is left alone; a file already copied by
 * an interrupted run is not copied again; a row whose file is in neither place is reported, not
 * changed. Files are never deleted from the old disk until the copy is in place.
 */
final class RelocateAttachments
{
    /** @return array{moved: int, already: int, missing: list<int>} missing: attachment ids */
    public function execute(): array
    {
        $from = Storage::disk('odk');
        $to = Storage::disk((string) config('edqa.odk.attachments.disk', 'attachments'));
        $report = ['moved' => 0, 'already' => 0, 'missing' => []];

        SubmissionAttachment::query()->with('submission:id,instance_id,source_id', 'submission.source:id,key')
            ->where('status', AttachmentStatus::Fetched)->whereNotNull('path')->orderBy('id')
            ->each(function (SubmissionAttachment $attachment) use ($from, $to, &$report): void {
                $old = (string) $attachment->path;
                $key = (string) $attachment->submission?->source?->key;
                $target = AttachmentRules::directory((string) $attachment->submission?->instance_id, $key).'/'.basename($old);
                if ($old === $target && $to->exists($target)) {
                    $report['already']++;

                    return;
                }

                if (! $to->exists($target)) {
                    if (! $from->exists($old)) {
                        $report['missing'][] = $attachment->id;

                        return;
                    }
                    self::copy($from, $old, $to, $target);
                }
                $attachment->update(['path' => $target]);
                if ($from->exists($old)) {
                    $from->delete($old);
                }
                $report['moved']++;
            });

        return $report;
    }

    private static function copy(Filesystem $from, string $old, Filesystem $to, string $target): void
    {
        $stream = $from->readStream($old);
        $to->writeStream($target, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
