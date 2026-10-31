<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Ingestion\Queries\BackfillStatusQuery;
use App\Domain\Ingestion\Queries\DeletionsAtSourceQuery;
use App\Domain\Ingestion\Queries\PullHistoryQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sources\SourcesPayload;
use App\Http\Requests\Admin\PullHistoryRequest;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Data sources page (ARCHITECTURE.md §9.3), which replaces the ODK pull page: every source
 * (the same entries as GET /api/sources), the combined pull history with its filters, the
 * deletions at source, the backfill's read-only status with a signed link to the newest report,
 * and the project documents' links. The page acts through the sources API and reloads only what
 * changed.
 */
final class AdminSourcesController extends Controller
{
    /** How long a backfill report link works (SECURITY.md §7: 30 minutes). */
    public const SIGNED_MINUTES = 30;

    public function __invoke(PullHistoryRequest $request, SourcesPayload $sources, BackfillStatusQuery $backfill, PullHistoryQuery $history, DeletionsAtSourceQuery $deletions): Response
    {
        return Inertia::render('admin/sources', [
            'sources' => fn (): array => $sources->build(),
            'backfill' => function () use ($backfill): array {
                $status = $backfill->backfill();
                $report = $status['report'];

                return [
                    ...$status,
                    'report' => $report === null ? null : [
                        ...$report,
                        'url' => URL::temporarySignedRoute('api.backfill-reports.show', now()->addMinutes(self::SIGNED_MINUTES), ['file' => $report['file']]),
                    ],
                ];
            },
            'history' => fn () => $history->paginate($request->filters()),
            'historyFilters' => $request->filters(),
            'deletions' => fn (): array => $deletions->list(),
            'docs' => [
                'runbook' => self::doc('docs/runbooks/source-credentials-day.md'),
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
