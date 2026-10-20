<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facilities;

use App\Domain\Facility\Exports\CascadeExporter;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ODK cascading-select media (ARCHITECTURE.md §5.1, §8, SECURITY.md §7). POST
 * /api/facility-list/export-cascade writes a fresh zip to the private exports disk and answers
 * 201 with a signed download link valid for SIGNED_MINUTES; GET follows that link, inside the auth
 * group and only with a valid signature. Both are audited. Uploading the files to ODK Central is
 * done by hand for now (docs/odk-cascade-media.md).
 */
final class CascadeExportController extends Controller
{
    public const SIGNED_MINUTES = 30;

    public function store(Request $request, CascadeExporter $exporter): JsonResponse
    {
        $export = $exporter->export();
        $expires = now()->addMinutes(self::SIGNED_MINUTES);
        AuditLog::record(null, self::actor($request), 'cascade_exported', old: [], new: [
            'file' => $export['filename'], 'lgas' => $export['lgas'], 'wards' => $export['wards'], 'facilities' => $export['facilities'],
        ]);

        return response()->json(['data' => [
            'filename' => $export['filename'],
            'lgas' => $export['lgas'],
            'wards' => $export['wards'],
            'facilities' => $export['facilities'],
            'url' => URL::temporarySignedRoute('api.facility-list.cascade.download', $expires, ['file' => $export['filename']]),
            'expires_at' => $expires->toIso8601String(),
        ]], 201);
    }

    public function download(Request $request, string $file): StreamedResponse
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('exports');
        $path = CascadeExporter::DIRECTORY.'/'.$file;
        if (preg_match(CascadeExporter::FILE_PATTERN, $file) !== 1 || ! $disk->exists($path)) {
            abort(404);
        }
        AuditLog::record(null, self::actor($request), 'cascade_downloaded', old: [], new: ['file' => $file]);

        return $disk->download($path, $file, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private static function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
