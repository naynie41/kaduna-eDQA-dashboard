<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Ingestion\Queries\OdkStatusQuery;
use App\Domain\Ingestion\Queries\PullHistoryQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Odk\OdkStatusPayload;
use App\Http\Requests\Admin\PullHistoryRequest;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ODK pull page (ARCHITECTURE.md §9.3, D-56): the connection (the same payload as
 * GET /api/odk/status), the backfill's read-only status with a signed link to the newest report,
 * and the pull history. The page asks the API for the status again after a pull, and reloads
 * only the history when its filters or page change.
 */
final class AdminOdkController extends Controller
{
    /** How long a backfill report link works (SECURITY.md §7: 30 minutes). */
    public const SIGNED_MINUTES = 30;

    public function __invoke(PullHistoryRequest $request, OdkStatusPayload $status, OdkStatusQuery $odk, PullHistoryQuery $history): Response
    {
        $backfill = $odk->backfill();
        $report = $backfill['report'];

        return Inertia::render('admin/odk', [
            'status' => fn (): array => $status->build(),
            'backfill' => fn (): array => [
                ...$backfill,
                'report' => $report === null ? null : [
                    ...$report,
                    'url' => URL::temporarySignedRoute('api.odk.backfill-report', now()->addMinutes(self::SIGNED_MINUTES), ['file' => $report['file']]),
                ],
            ],
            'history' => fn () => $history->paginate($request->filters()),
            'historyFilters' => $request->filters(),
            'docs' => [
                'runbook' => self::doc('docs/runbooks/odk-credentials-day.md'),
                'cascade' => self::doc('docs/odk-cascade-media.md'),
            ],
        ]);
    }

    /** A project document's link (edqa.docs.base_url), or null when none is configured. */
    private static function doc(string $path): ?string
    {
        $base = config('edqa.docs.base_url');

        return is_string($base) && $base !== '' ? rtrim($base, '/').'/'.$path : null;
    }
}
