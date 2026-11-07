<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Api;

use App\Domain\Reporting\Aggregators\FacilityScoresQuery;
use App\Domain\Reporting\Queries\FilterOptionsQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\FacilityController;
use App\Http\Controllers\Public\PublicFilter;
use App\Http\Resources\Public\FacilityPageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/public/facilities (ARCHITECTURE.md §8): the facility table, 50 a page, sortable and
 * filterable as on the Facility view, closed rounds only (D-77), through FacilityPageResource.
 */
final class FacilitiesController extends Controller
{
    public function __invoke(Request $request, FilterOptionsQuery $options, FacilityScoresQuery $facilities): JsonResponse
    {
        [$sort, $page] = FacilityController::sortAndPage($request);

        return FacilityPageResource::make($facilities->paginate(PublicFilter::from($request, $options), $sort, $page))->response();
    }
}
