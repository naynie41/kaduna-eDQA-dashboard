<?php

declare(strict_types=1);

use App\Domain\Assessment\Exceptions\CorrectionRefused;
use App\Domain\Round\Exceptions\RoundActionRefused;
use App\Domain\Scoring\Exceptions\InvalidRuleConfig;
use App\Domain\Scoring\Exceptions\RescoreRefused;
use App\Domain\Scoring\Exceptions\RuleVersionRefused;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Security headers first, so they are set on every web response.
        $middleware->web(prepend: [SecurityHeaders::class]);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias(['two-factor.confirmed' => EnsureTwoFactorConfirmed::class]);

        // The ODK webhook is protected by its HMAC signature instead (SECURITY.md §6).
        $middleware->validateCsrfTokens(except: ['webhooks/odk']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Domain refusals: 409 { code, message } (ARCHITECTURE.md §8 conventions).
        $refusal = fn (QuarantineActionRefused|RuleVersionRefused|InvalidRuleConfig|RescoreRefused|RoundActionRefused|CorrectionRefused $e) => response()->json(
            ['code' => $e->errorCode, 'message' => $e->getMessage()],
            409,
        );
        $exceptions->render(fn (QuarantineActionRefused $e) => $refusal($e));
        $exceptions->render(fn (RuleVersionRefused $e) => $refusal($e));
        $exceptions->render(fn (InvalidRuleConfig $e) => $refusal($e));
        $exceptions->render(fn (RescoreRefused $e) => $refusal($e));
        $exceptions->render(fn (RoundActionRefused $e) => $refusal($e));
        $exceptions->render(fn (CorrectionRefused $e) => $refusal($e));
    })->create();
