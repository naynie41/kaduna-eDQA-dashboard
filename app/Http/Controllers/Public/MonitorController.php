<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Reporting\Aggregators\AssessmentWindowAggregator;
use App\Domain\Reporting\Aggregators\CoverageAggregator;
use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\AssessmentWindowResource;
use App\Http\Resources\Public\CoverageResource;
use App\Http\Resources\Public\DimensionScoresResource;
use App\Http\Resources\Public\OwnerSplitResource;
use App\Http\Resources\Public\StateSummaryResource;
use Inertia\Inertia;
use Inertia\Response;

/** Monitor (ARCHITECTURE.md §9.2.4): owner split, coverage, the assessment window and the assessments by source, deferred. */
final class MonitorController extends Controller
{
    public function __invoke(ReportFilter $filter, OwnerSplitAggregator $owners, CoverageAggregator $coverage, AssessmentWindowAggregator $windows, SourceCountsPayload $sources, StateSummaryAggregator $state, DimensionAggregator $dimensions): Response
    {
        return Inertia::render('dashboard/monitor', [
            // All facilities, public and private together, narrowed by the Owner filter.
            'overall' => Inertia::defer(fn (): array => ['summary' => StateSummaryResource::one($state->summary($filter)), 'dimensions' => DimensionScoresResource::one($dimensions->dimensions($filter))]),
            'owners' => Inertia::defer(fn () => OwnerSplitResource::one($owners->split($filter))),
            'coverage' => Inertia::defer(fn () => CoverageResource::one($coverage->coverage($filter))),
            'windows' => Inertia::defer(fn () => AssessmentWindowResource::many($windows->windows($filter))),
            // Counts by source are source information, the admin preview's only (D-81).
            ...($filter->audience === ReportAudience::Admin ? ['sources' => Inertia::defer(fn () => $sources->build($filter))] : []),
        ]);
    }
}
