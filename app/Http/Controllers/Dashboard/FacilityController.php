<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Domain\Reporting\Aggregators\FacilityScoresQuery;
use App\Domain\Reporting\DTOs\FacilitySort;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Facility view (ARCHITECTURE.md §9.2.2): facilities weakest first, 50 a page, deferred. */
final class FacilityController extends Controller
{
    public function __invoke(Request $request, ReportFilter $filter, FacilityScoresQuery $facilities): Response
    {
        $page = max(1, $request->integer('page', 1));

        return Inertia::render('dashboard/facility', [
            'facilities' => Inertia::defer(fn () => $facilities->paginate($filter, FacilitySort::default(), $page)),
        ]);
    }
}
