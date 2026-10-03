<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA requirement (SECURITY.md §2): a signed-in administrator without confirmed TOTP reaches only
 * the 2FA setup page (and Fortify's 2FA and logout routes, which sit outside this middleware).
 * Skipped when EDQA_REQUIRE_2FA=false (D-26).
 */
final class EnsureTwoFactorConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('edqa.auth.require_two_factor') === true
            && $user instanceof User && $user->two_factor_confirmed_at === null) {
            return $request->expectsJson()
                ? response()->json(['message' => __('auth.two_factor.required')], 403)
                : redirect()->route('two-factor.setup');
        }

        return $next($request);
    }
}
