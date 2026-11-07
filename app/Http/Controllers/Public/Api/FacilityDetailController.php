<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Api;

use App\Domain\Facility\Models\Facility;
use App\Domain\Reporting\Aggregators\FacilityDetailAggregator;
use App\Domain\Reporting\Queries\FilterOptionsQuery;
use App\Domain\Round\Models\Round;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\PublicFilter;
use App\Http\Resources\Public\FacilityDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/public/facilities/{id} (ARCHITECTURE.md §8, §9.2.7): the public facility drawer.
 * ?round= a round to open (one the audience cannot see, or none, is ignored), else ?year= and
 * ?quarter= as on the page. Closed rounds only for a visitor (D-77); the facility's name, place,
 * level and ownership, the score card, the item results and the round history, nothing else
 * (SECURITY.md §3.1, FacilityDetailResource).
 */
final class FacilityDetailController extends Controller
{
    public function __invoke(Request $request, Facility $facility, FilterOptionsQuery $options, FacilityDetailAggregator $details): JsonResponse
    {
        $round = is_string($request->query('round')) && ctype_digit($request->query('round'))
            ? Round::query()->find((int) $request->query('round'))
            : null;

        return FacilityDetailResource::make($details->forFacility($facility, $round, PublicFilter::from($request, $options)))->response();
    }
}
