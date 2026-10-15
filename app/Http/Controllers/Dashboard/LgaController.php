<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** LGA view (ARCHITECTURE.md §9.2.3): the 23-LGA grid and table, deferred. */
final class LgaController extends Controller
{
    public function __invoke(ReportFilter $filter, LgaTableAggregator $lgas): Response
    {
        return Inertia::render('dashboard/lga', [
            'lgas' => Inertia::defer(fn () => $lgas->rows($filter)),
        ]);
    }
}
