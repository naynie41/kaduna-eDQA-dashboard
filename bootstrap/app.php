<?php

declare(strict_types=1);

use App\Domain\Assessment\Exceptions\CorrectionRefused;
use App\Domain\Ingestion\Exceptions\AssessmentStatusRefused;
use App\Domain\Ingestion\Exceptions\DeletionReviewRefused;
use App\Domain\Round\Exceptions\RoundActionRefused;
use App\Domain\Scoring\Exceptions\InvalidRuleConfig;
use App\Domain\Scoring\Exceptions\RescoreRefused;
use App\Domain\Scoring\Exceptions\RuleVersionRefused;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoIndex;
use App\Http\Middleware\PublicCacheHeaders;
use App\Http\Middleware\PublicReadOnly;
use App\Http\Middleware\RestrictToAllowedIps;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // SECURITY.md §3: the admin area (/admin, admin.*) and the source webhooks, outside the web group.
        then: function (): void {
            Route::middleware(['web', 'admin.ip', 'auth'])->prefix('admin')->name('admin.')
                ->group(base_path('routes/admin.php'));
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // D-82: X-Robots-Tag on every response, errors, health check and webhooks included.
        $middleware->append(NoIndex::class);

        // Security headers first, so they are set on every web response.
        $middleware->web(prepend: [
            // Outermost, so Inertia's Vary header is already set: public pages' Cache-Control (ARCHITECTURE.md §10).
            PublicCacheHeaders::class,
            SecurityHeaders::class,
        ]);
        $middleware->web(append: [
            // SECURITY.md §2: signed out 12 hours after sign-in, however active.
            EnforceAbsoluteSessionLifetime::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Forwarded headers count only from a private-network peer (a tunnel or proxy on the host or
        // Docker network). Behind FastCGI, REMOTE_ADDR is the real client, so an internet client
        // cannot choose the host the app builds its URLs from.
        $middleware->trustProxies(
            at: ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1', 'fc00::/7'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        // SECURITY.md §2: the optional admin IP allow-list (EDQA_ADMIN_ALLOWED_IPS).
        // Before auth, so an address outside it gets 404, not a redirect to the login page.
        $middleware->alias(['admin.ip' => RestrictToAllowedIps::class, 'public.readonly' => PublicReadOnly::class]);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: RestrictToAllowedIps::class);

        // The source webhooks (/webhooks/{source}) are each verified their own way instead, ODK by
        // its HMAC signature and Kobo by its token header (SECURITY.md §6); nothing else is exempt.
        $middleware->preventRequestForgery(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Domain refusals: 409 { code, message } (ARCHITECTURE.md §8 conventions).
        $refusal = fn (QuarantineActionRefused|RuleVersionRefused|InvalidRuleConfig|RescoreRefused|RoundActionRefused|CorrectionRefused|DeletionReviewRefused|AssessmentStatusRefused $e) => response()->json(
            ['code' => $e->errorCode, 'message' => $e->getMessage()],
            409,
        );
        $exceptions->render(fn (QuarantineActionRefused $e) => $refusal($e));
        $exceptions->render(fn (RuleVersionRefused $e) => $refusal($e));
        $exceptions->render(fn (InvalidRuleConfig $e) => $refusal($e));
        $exceptions->render(fn (RescoreRefused $e) => $refusal($e));
        $exceptions->render(fn (RoundActionRefused $e) => $refusal($e));
        $exceptions->render(fn (CorrectionRefused $e) => $refusal($e));
        $exceptions->render(fn (DeletionReviewRefused $e) => $refusal($e));
        $exceptions->render(fn (AssessmentStatusRefused $e) => $refusal($e));
    })->create();
