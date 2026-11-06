<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The signed-in dashboard's old URLs (/dashboard/*) moved to the public ones (ARCHITECTURE.md
 * §9.0, D-76): a permanent redirect, keeping the filters, so bookmarks and shared links still work.
 */
final class OldDashboardUrlController extends Controller
{
    public const PATHS = [
        '' => '/',
        'home' => '/',
        'facility' => '/facilities',
        'lga' => '/lgas',
        'monitor' => '/monitor',
        'visuals' => '/visuals',
        'plan' => '/plan',
    ];

    public function __invoke(Request $request, string $page = ''): RedirectResponse
    {
        // http_build_query keeps the order the link had; getQueryString() would sort it.
        $query = http_build_query($request->query());

        return redirect()->to(self::PATHS[$page].($query === '' ? '' : "?{$query}"), 301);
    }
}
