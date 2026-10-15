<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Domain\Reporting\Aggregators\AssessmentWindowAggregator;
use App\Domain\Reporting\Aggregators\CoverageAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Monitor (ARCHITECTURE.md §9.2.4): owner split, coverage and the assessment window, deferred. */
final class MonitorController extends Controller
{
    public function __invoke(ReportFilter $filter, OwnerSplitAggregator $owners, CoverageAggregator $coverage, AssessmentWindowAggregator $windows): Response
    {
        return Inertia::render('dashboard/monitor', [
            'owners' => Inertia::defer(fn () => $owners->split($filter)),
            'coverage' => Inertia::defer(fn () => $coverage->coverage($filter)),
            'windows' => Inertia::defer(fn () => $windows->windows($filter)),
        ]);
    }
}
