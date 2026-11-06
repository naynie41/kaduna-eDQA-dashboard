<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `X-Robots-Tag: noindex, nofollow` on every response (SECURITY.md §8, D-82), errors and the
 * health check included. Global, so nothing can miss it. Only when `edqa.public.indexable` is on
 * may the public dashboard pages go without it; /admin, /login and the rest never do.
 */
final class NoIndex
{
    public const HEADER = 'noindex, nofollow';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! self::indexable($request)) {
            $response->headers->set('X-Robots-Tag', self::HEADER);
        }

        return $response;
    }

    /** The layout's robots meta tag asks the same question. */
    public static function indexable(Request $request): bool
    {
        return (bool) config('edqa.public.indexable') && $request->routeIs('public.*');
    }
}
