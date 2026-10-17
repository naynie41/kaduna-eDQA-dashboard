<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Domain\Reporting\Aggregators\ImplementationPlanAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Implementation plan (ARCHITECTURE.md §9.2.6), read-only: the plan from
 * ImplementationPlanAggregator, deferred. Editing owners, dates and statuses, and the Excel
 * workplan, arrive in Phase 6.
 */
final class PlanController extends Controller
{
    public function __invoke(ReportFilter $filter, ImplementationPlanAggregator $plan): Response
    {
        return Inertia::render('dashboard/plan', [
            'plan' => Inertia::defer(fn () => $plan->plan($filter)),
        ]);
    }
}
