<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\MonthSlotAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Visuals (ARCHITECTURE.md §9.2.5): month-slot series and the LGA rankings, deferred. */
final class VisualsController extends Controller
{
    public function __invoke(ReportFilter $filter, MonthSlotAggregator $months, LgaTableAggregator $lgas): Response
    {
        return Inertia::render('dashboard/visuals', [
            'monthSlots' => Inertia::defer(fn () => $months->series($filter)),
            'lgas' => Inertia::defer(fn () => $lgas->rows($filter)),
        ]);
    }
}
