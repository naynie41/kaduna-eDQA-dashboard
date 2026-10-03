<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP security headers (SECURITY.md §8). Scripts run only from this origin or with the
 * per-request nonce that Vite puts on every tag it renders. Fonts are self-hosted, so no
 * external host is allowed anywhere.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        // Local development only: the Vite dev server serves scripts and styles, and hot
        // reloading uses a websocket, from its own origin.
        $dev = Vite::isRunningHot() ? $this->devServerOrigin() : null;
        $devSources = $dev === null ? '' : " {$dev}";
        $devSocket = $dev === null ? '' : ' '.preg_replace('#^http#', 'ws', $dev);

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$devSources}",
            "style-src 'self' 'unsafe-inline'{$devSources}",
            "img-src 'self' data: blob:",
            "font-src 'self'{$devSources}",
            "connect-src 'self'{$devSources}{$devSocket}",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }

    private function devServerOrigin(): ?string
    {
        $hot = @file_get_contents(public_path('hot'));

        return is_string($hot) && preg_match('#^https?://[^/\s]+#', trim($hot), $m) ? $m[0] : null;
    }
}
