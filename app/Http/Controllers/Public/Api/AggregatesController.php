<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Api;

use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\Queries\FilterOptionsQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\PublicFilter;
use App\Http\Resources\Public\DimensionScoresResource;
use App\Http\Resources\Public\LgaRowResource;
use App\Http\Resources\Public\OwnerSplitResource;
use App\Http\Resources\Public\StateSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/public/aggregates (ARCHITECTURE.md §8): scores by scope for the five filters, closed
 * rounds only (D-77): the filters as applied, the state summary, the three dimensions, the Public /
 * Private split and the LGA rows. Through public resources only (D-83).
 */
final class AggregatesController extends Controller
{
    public function __invoke(
        Request $request,
        FilterOptionsQuery $options,
        StateSummaryAggregator $state,
        DimensionAggregator $dimensions,
        OwnerSplitAggregator $owners,
        LgaTableAggregator $lgas,
    ): JsonResponse {
        $filter = PublicFilter::from($request, $options);

        return response()->json(['data' => [
            'filters' => $filter->toProps(),
            'summary' => StateSummaryResource::one($state->summary($filter)),
            'dimensions' => DimensionScoresResource::one($dimensions->dimensions($filter)),
            'owners' => OwnerSplitResource::one($owners->split($filter)),
            'lgas' => LgaRowResource::many($lgas->rows($filter)),
        ]]);
    }
}
