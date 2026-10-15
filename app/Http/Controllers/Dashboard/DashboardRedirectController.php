<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** `/` and `/dashboard` open the Home page, keeping any filters in the query string. */
final class DashboardRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // http_build_query keeps the order the link had; getQueryString() would sort it.
        $query = http_build_query($request->query());

        return redirect()->to('/dashboard/home'.($query === '' ? '' : "?{$query}"));
    }
}
