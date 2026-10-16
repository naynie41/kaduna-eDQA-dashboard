<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Round\Models\Round;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * LGA view (ARCHITECTURE.md §9.2.3). `tiles`: every LGA for the grid, the LGA filter left out so
 * another can always be chosen (the chosen one is pressed). `lgas`: the table, in the filter's
 * scope. `rounds`: the rounds in view, for the month headings (real names for one quarter).
 * Both lists come from LgaTableAggregator, which reads the lgas table: never more than 23.
 */
final class LgaController extends Controller
{
    public function __invoke(ReportFilter $filter, RoundSelection $rounds, LgaTableAggregator $lgas): Response
    {
        $everyLga = new ReportFilter(year: $filter->year, quarter: $filter->quarter, level: $filter->level, owner: $filter->owner);

        return Inertia::render('dashboard/lga', [
            'rounds' => array_map(fn (Round $round): array => ['year' => $round->year, 'quarter' => $round->quarter], $rounds->current($filter)),
            // Not deferred: a deferred prop is dropped on every filter reload, which would unmount
            // the grid and lose the focused tile. 23 rows, read from round_aggregates.
            'tiles' => $lgas->rows($everyLga),
            'lgas' => Inertia::defer(fn () => $lgas->rows($filter)),
        ]);
    }
}
