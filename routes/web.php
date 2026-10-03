<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\OdkWebhookController;
use Illuminate\Support\Facades\Route;

/*
| SECURITY.md §3: one protected group is the whole authorisation model. Outside it are only
| Fortify's login, 2FA challenge and password-reset screens, the ODK webhook (HMAC-signed) and
| GET /up. tests/Feature/Auth/RouteGuardTest.php enforces this for every registered route.
*/

Route::post('webhooks/odk', OdkWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.odk');

// Signed in but 2FA not yet confirmed: the only page such a user can reach.
Route::middleware(['auth', 'verified', 'password.confirm'])->group(function (): void {
    Route::get('two-factor/setup', TwoFactorSetupController::class)->name('two-factor.setup');
});

// Recovery codes are shown once, when issued, and never again (SECURITY.md §2). This replaces
// Fortify's endpoint that would re-display them.
Route::get('user/two-factor-recovery-codes', fn () => abort(404))
    ->middleware('auth')
    ->name('two-factor.recovery-codes');

Route::middleware(['auth', 'verified', 'two-factor.confirmed'])->group(function (): void {
    Route::inertia('/', 'dashboard')->name('home');

    require __DIR__.'/settings.php';
});
