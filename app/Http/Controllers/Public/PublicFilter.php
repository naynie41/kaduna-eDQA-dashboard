<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Reporting\Queries\FilterOptionsQuery;
use Illuminate\Http\Request;

/**
 * The public API's reading of the query string: the same as the dashboard pages
 * (ShareDashboardContext), without the redirect. The audience comes from the session, so the
 * admin preview sees the open round here too; a visitor never does (D-77).
 */
final class PublicFilter
{
    public static function from(Request $request, FilterOptionsQuery $options): ReportFilter
    {
        $audience = ReportAudience::of($request->user());
        $choices = $options->get($audience);
        /** @var array<string, mixed> $query */
        $query = $request->query();

        return ReportFilter::resolve($query, $choices['rounds'], array_column($choices['lgas'], 'code'), $audience);
    }
}
