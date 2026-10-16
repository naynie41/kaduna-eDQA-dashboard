<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Domain\Reporting\Aggregators\FacilityScoresQuery;
use App\Domain\Reporting\DTOs\FacilitySort;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\Metric;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Round\Models\Round;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Facility view (ARCHITECTURE.md §9.2.2): the facilities in view, 50 a page, sorted on the server.
 * ?metric= picks the measure (default overall), ?sort= one of FacilitySort::COLUMNS and ?dir=
 * asc|desc (default: weakest first by the metric). Anything else falls back to that default:
 * request text never reaches SQL, and the page size is not the caller's to choose.
 *
 * One deferred prop, so a partial reload (only: ['facilities']) brings everything the table
 * needs: the page, the sort and metric actually applied, and the quarters in view.
 */
final class FacilityController extends Controller
{
    public function __invoke(Request $request, ReportFilter $filter, RoundSelection $rounds, FacilityScoresQuery $facilities): Response
    {
        $metric = Metric::tryFrom(self::param($request, 'metric')) ?? Metric::Overall;
        $column = self::param($request, 'sort');
        $direction = self::param($request, 'dir');
        $sort = in_array($column, FacilitySort::COLUMNS, true)
            ? new FacilitySort($metric, $column, in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc')
            : FacilitySort::default($metric);
        $page = max(1, (int) self::param($request, 'page'));

        return Inertia::render('dashboard/facility', [
            'facilities' => Inertia::defer(fn (): array => [
                'page' => $facilities->paginate($filter, $sort, $page),
                'metric' => $metric->value,
                'sort' => ['column' => $sort->column, 'direction' => $sort->direction],
                'quarters' => array_values(array_unique(array_map(fn (Round $round): int => $round->quarter, $rounds->current($filter)))),
            ]),
        ]);
    }

    /** A single string query value, or '' (arrays and anything else are ignored). */
    private static function param(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }
}
