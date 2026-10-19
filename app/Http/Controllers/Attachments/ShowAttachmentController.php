<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attachments;

use App\Domain\Ingestion\Queries\AttachmentFileQuery;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /attachments/{submission}/{filename} (SECURITY.md §7): streams one fetched ODK attachment
 * from the private odk disk, inside the authenticated group. Images open inline (the drawer's
 * thumbnails), anything else downloads. The stored type is the sniffed one; nosniff stops the
 * browser second-guessing it, and the response is cached privately only. Every view is audited.
 */
final class ShowAttachmentController extends Controller
{
    public function __invoke(Request $request, int $submission, string $filename, AttachmentFileQuery $files): StreamedResponse
    {
        $attachment = $files->find($submission, $filename);
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('edqa.odk.attachments.disk', 'odk'));
        if ($attachment === null || ! $disk->exists((string) $attachment->path)) {
            abort(404);
        }

        $user = $request->user();
        AuditLog::record($attachment, $user instanceof User ? $user : null, 'viewed', old: [], new: [
            'submission_id' => $attachment->submission_id, 'filename' => $attachment->filename,
        ]);

        $mime = (string) $attachment->mime;
        $disposition = str_starts_with($mime, 'image/') ? 'inline' : 'attachment';

        return $disk->response((string) $attachment->path, (string) $attachment->filename, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], $disposition);
    }
}
