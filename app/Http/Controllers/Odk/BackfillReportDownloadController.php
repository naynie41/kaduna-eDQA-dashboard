<?php

declare(strict_types=1);

namespace App\Http\Controllers\Odk;

use App\Domain\Ingestion\Queries\OdkStatusQuery;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/odk/backfill-report/{file} (D-56): a backfill report from the private exports disk,
 * through a signed link (30 minutes) inside the auth group; only a backfill report's file name is
 * served. Audited.
 */
final class BackfillReportDownloadController extends Controller
{
    public function __invoke(Request $request, string $file): StreamedResponse
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('exports');
        if (preg_match(OdkStatusQuery::REPORT_PATTERN, $file) !== 1 || ! $disk->exists($file)) {
            abort(404);
        }
        $user = $request->user();
        AuditLog::record(null, $user instanceof User ? $user : null, 'backfill_report_downloaded', old: [], new: ['file' => $file]);

        return $disk->download($file, $file, [
            'Content-Type' => str_ends_with($file, '.csv') ? 'text/csv; charset=UTF-8' : 'text/markdown; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
