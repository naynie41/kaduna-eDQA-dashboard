<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Facility\Models\Facility;
use App\Domain\Reporting\Aggregators\FacilityDetailAggregator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityDetailRequest;
use App\Http\Resources\Facilities\FacilityDetailResource;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin facility view, /admin/facilities/{id} (ARCHITECTURE.md §9.2.7): the full detail, as
 * GET /admin/api/facilities/{id} returns it, for every round (the open one too): the assessor,
 * soft flags, attachments, the audit trail, the source with its instance ID and deep link, and
 * "Correct this assessment" while the round is open (D-58). ?round= picks the round.
 */
final class AdminFacilityController extends Controller
{
    public function __invoke(FacilityDetailRequest $request, Facility $facility, FacilityDetailAggregator $details): Response
    {
        return Inertia::render('admin/facility', [
            'detail' => (new FacilityDetailResource($details->forFacility($facility, $request->round(), $request->filter())))->resolve(),
        ]);
    }
}
