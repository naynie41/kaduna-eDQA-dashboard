<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public pages' cache headers (ARCHITECTURE.md §10), at the outer edge of the web group so it runs
 * after Inertia has set its Vary header: a visitor's public page or public JSON may be kept by the
 * browser a short while (edqa.public.max_age seconds); the figures change only when a round closes
 * or is rescored. Private, not public: every response carries a session cookie, which no shared
 * cache may keep. An administrator's preview (open round included) is never kept. Errors and
 * redirects keep the framework's headers.
 */
final class PublicCacheHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs('public.*') || ! $response->isSuccessful()) {
            return $response;
        }

        if ($request->user() === null) {
            $response->headers->set('Cache-Control', 'private, max-age='.(int) config('edqa.public.max_age'));
        } else {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        $response->setVary(['X-Inertia', 'Cookie'], false);

        return $response;
    }
}
