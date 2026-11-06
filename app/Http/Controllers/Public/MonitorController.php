<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Reporting\Aggregators\AssessmentWindowAggregator;
use App\Domain\Reporting\Aggregators\CoverageAggregator;
use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Monitor (ARCHITECTURE.md §9.2.4): owner split, coverage, the assessment window and the assessments by source, deferred. */
final class MonitorController extends Controller
{
    public function __invoke(ReportFilter $filter, OwnerSplitAggregator $owners, CoverageAggregator $coverage, AssessmentWindowAggregator $windows, SourceCountsPayload $sources, StateSummaryAggregator $state, DimensionAggregator $dimensions): Response
    {
        return Inertia::render('dashboard/monitor', [
            // All facilities, public and private together, narrowed by the Owner filter.
            'overall' => Inertia::defer(fn (): array => ['summary' => $state->summary($filter), 'dimensions' => $dimensions->dimensions($filter)]),
            'owners' => Inertia::defer(fn () => $owners->split($filter)),
            'coverage' => Inertia::defer(fn () => $coverage->coverage($filter)),
            'windows' => Inertia::defer(fn () => $windows->windows($filter)),
            'sources' => Inertia::defer(fn () => $sources->build($filter)),
        ]);
    }
}
