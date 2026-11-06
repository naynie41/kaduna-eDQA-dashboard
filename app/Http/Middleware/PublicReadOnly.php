<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `public.readonly` (SECURITY.md §3): the public dashboard is read-only by construction. Anything
 * but GET or HEAD is 405, whatever the route declares.
 */
final class PublicReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            abort(405, headers: ['Allow' => 'GET, HEAD']);
        }

        return $next($request);
    }
}
