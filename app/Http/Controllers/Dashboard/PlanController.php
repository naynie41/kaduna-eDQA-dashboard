<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Implementation plan (ARCHITECTURE.md §9.2.6). The LGA rows for now, deferred; the plan's own
 * aggregator and its editable actions arrive with the page (Phase 4).
 */
final class PlanController extends Controller
{
    public function __invoke(ReportFilter $filter, LgaTableAggregator $lgas): Response
    {
        return Inertia::render('dashboard/plan', [
            'lgas' => Inertia::defer(fn () => $lgas->rows($filter)),
        ]);
    }
}
