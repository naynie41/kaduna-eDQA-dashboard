<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Domain\Facility\Enums\Ownership;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\MonthSlotAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visuals (ARCHITECTURE.md §9.2.5): the month-slot series with their takeaways and the LGA
 * rankings, deferred. ?owner_view=public|private narrows both to one owner type without touching
 * the global owner filter; when that filter is set, the view is locked to it (`locked`).
 */
final class VisualsController extends Controller
{
    public function __invoke(Request $request, ReportFilter $filter, MonthSlotAggregator $months, LgaTableAggregator $lgas): Response
    {
        $asked = $request->query('owner_view');
        $view = $filter->owner ?? Ownership::tryFrom(is_string($asked) ? $asked : '');
        $viewFilter = $filter->withOwner($view);

        return Inertia::render('dashboard/visuals', [
            'ownerView' => ['value' => $view->value ?? 'all', 'locked' => $filter->owner?->value],
            'monthSlots' => Inertia::defer(function () use ($months, $viewFilter): array {
                $points = $months->series($viewFilter);

                return ['points' => $points, 'takeaways' => $months->takeaways($points)];
            }),
            'lgas' => Inertia::defer(fn () => $lgas->rows($viewFilter)),
        ]);
    }
}
