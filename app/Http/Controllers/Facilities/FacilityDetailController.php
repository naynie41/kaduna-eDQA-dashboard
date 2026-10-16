<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facilities;

use App\Domain\Facility\Models\Facility;
use App\Domain\Reporting\Aggregators\FacilityDetailAggregator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityDetailRequest;
use App\Http\Resources\Facilities\FacilityDetailResource;

/** GET /api/facilities/{facility}?round= (ARCHITECTURE.md §8): the facility detail drawer. */
final class FacilityDetailController extends Controller
{
    public function __invoke(FacilityDetailRequest $request, Facility $facility, FacilityDetailAggregator $detail): FacilityDetailResource
    {
        return new FacilityDetailResource($detail->forFacility($facility, $request->round(), $request->filter()));
    }
}
