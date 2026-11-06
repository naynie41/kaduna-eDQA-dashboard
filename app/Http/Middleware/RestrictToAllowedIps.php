<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The optional admin IP allow-list (SECURITY.md §2, EDQA_ADMIN_ALLOWED_IPS): when set, any other
 * address gets 404, as if the page did not exist. The client address is the one trusted proxies
 * forward (bootstrap/app.php), so Caddy's or a tunnel's forwarded IP is the one checked. Off when
 * the list is empty.
 */
final class RestrictToAllowedIps
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('edqa.auth.admin_allowed_ips');

        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            abort(404);
        }

        return $next($request);
    }
}
