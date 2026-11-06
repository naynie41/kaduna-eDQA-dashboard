<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs an administrator out 12 hours after sign-in, however active (SECURITY.md §2,
 * edqa.auth.absolute_session_hours); SESSION_LIFETIME is the idle limit. The clock starts at
 * sign-in, or at the next request for a session older than this rule. The logout is audited with
 * reason session_expired; an admin page then goes to /login and JSON gets 401, a public page is
 * shown signed out.
 */
final class EnforceAbsoluteSessionLifetime
{
    public const SESSION_KEY = 'auth.signed_in_at';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if ($guard->check()) {
            $started = $request->session()->get(self::SESSION_KEY);
            $limit = (int) config('edqa.auth.absolute_session_hours') * 3600;

            if (is_int($started) && now()->getTimestamp() - $started >= $limit) {
                $request->attributes->set('logout_reason', 'session_expired');
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                // On a public page the visitor just carries on signed out (D-76); elsewhere,
                // back to /login (or 401 for JSON).
                if (! $request->routeIs('public.*')) {
                    throw new AuthenticationException;
                }
            }
        }

        $response = $next($request);

        if ($guard->check() && ! $request->session()->has(self::SESSION_KEY)) {
            $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
        }

        return $response;
    }
}
