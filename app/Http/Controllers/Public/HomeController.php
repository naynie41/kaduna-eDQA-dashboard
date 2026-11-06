<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\MonthSlotAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\QuarterTrendAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\Aggregators\WeakestLgasAggregator;
use App\Domain\Reporting\Aggregators\WeakestWardsAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Support\RoundSelection;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home (ARCHITECTURE.md §9.2.1). The hero (average, minis, the three dimensions) is in the first
 * response; the owner split, the trend and the weakest list are deferred. The trend covers every
 * round with the LGA, level and owner filters applied, and marks the rounds the summaries cover.
 * With an LGA chosen the weakest list is of its wards.
 */
final class HomeController extends Controller
{
    public function __invoke(
        ReportFilter $filter,
        RoundSelection $rounds,
        StateSummaryAggregator $state,
        DimensionAggregator $dimensions,
        OwnerSplitAggregator $owners,
        QuarterTrendAggregator $trend,
        WeakestLgasAggregator $weakestLgas,
        WeakestWardsAggregator $weakestWards,
        MonthSlotAggregator $months,
    ): Response {
        return Inertia::render('dashboard/home', [
            'hero' => $state->summary($filter),
            'dimensions' => $dimensions->dimensions($filter),
            'owners' => Inertia::defer(fn () => $owners->split($filter)),
            'trend' => Inertia::defer(function () use ($trend, $filter, $rounds): array {
                $points = $trend->trend($filter->withoutRound());

                return [
                    'points' => $points,
                    'selected' => RoundSelection::ids($rounds->current($filter)),
                    'takeaway' => $trend->takeaway($points),
                ];
            }),
            // The quarter in view, month by month (D-75): each month's three dimensions and
            // overall. Empty when the view spans more than one quarter.
            'months' => Inertia::defer(function () use ($months, $filter, $rounds): array {
                $inView = RoundSelection::ids($rounds->current($filter));

                return count($inView) !== 1 ? [] : array_values(array_filter(
                    $months->series($filter),
                    fn ($point): bool => $point->roundId === $inView[0],
                ));
            }),
            'weakest' => Inertia::defer(fn (): array => $filter->lga === null
                ? ['scope' => 'lgas', 'rows' => $weakestLgas->weakest($filter)]
                : ['scope' => 'wards', 'rows' => $weakestWards->weakest($filter)]),
        ]);
    }
}
