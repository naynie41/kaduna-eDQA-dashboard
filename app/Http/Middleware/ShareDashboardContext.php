<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Queries\FilterOptionsQuery;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dashboard pages' shared context (ARCHITECTURE.md §9.1): resolves the ReportFilter once per
 * request and binds it for the controllers; invalid filters fall back to "all" and the browser is
 * sent to the cleaned URL (other parameters, such as page, kept); shares the filters, the filter
 * bar's options, and the summary the shell shows (facilities in view, LGA count, average).
 */
final class ShareDashboardContext
{
    public function __construct(
        private readonly FilterOptionsQuery $options,
        private readonly StateSummaryAggregator $summary,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $options = $this->options->get();
        /** @var array<string, mixed> $query */
        $query = $request->query();
        $filter = ReportFilter::resolve($query, $options['rounds'], array_column($options['lgas'], 'code'));

        // Redirect only when a value changes, never for key order: Inertia reports page URLs with
        // the keys sorted, and its deferred-prop reloads use that URL.
        $clean = [...$filter->toQuery(), ...array_diff_key($query, array_flip(ReportFilter::KEYS))];
        $sorted = function (array $values): string {
            ksort($values);

            return http_build_query($values);
        };
        if ($request->isMethod('GET') && $sorted($query) !== $sorted($clean)) {
            return redirect()->to($request->url().($clean === [] ? '' : '?'.http_build_query($clean)));
        }

        app()->instance(ReportFilter::class, $filter);

        Inertia::share([
            'filters' => $filter->toProps(),
            'filterOptions' => $options,
            'summary' => function () use ($filter): array {
                $summary = $this->summary->summary($filter);

                return ['facilities' => $summary->facilitiesVisited, 'lgas' => $summary->lgasCovered, 'overall' => $summary->overall];
            },
        ]);

        return $next($request);
    }
}
