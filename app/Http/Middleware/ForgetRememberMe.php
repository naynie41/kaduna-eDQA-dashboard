<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No "remember me" (SECURITY.md §2): a remember cookie would sign an administrator back in past
 * the 2-hour idle and 12-hour absolute session limits. Dropped from the sign-in request, whatever
 * the form sends.
 */
final class ForgetRememberMe
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->request->remove('remember');
        $request->query->remove('remember');
        if ($request->isJson()) {
            $request->json()->remove('remember');
        }

        return $next($request);
    }
}
