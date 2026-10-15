<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\OdkWebhookController;
use App\Http\Controllers\Quarantine\BulkResolveQuarantineController;
use App\Http\Controllers\Quarantine\OverrideSoftFlagController;
use App\Http\Controllers\Quarantine\QuarantineIndexController;
use App\Http\Controllers\Quarantine\ResolveQuarantineController;
use App\Http\Controllers\Rounds\RoundController;
use App\Http\Controllers\Scoring\RescoreController;
use App\Http\Controllers\Scoring\ScoringRuleController;
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

    // JSON for admin interactivity (ARCHITECTURE.md §8): same session, same guard. 120/min per
    // user (SECURITY.md §8).
    Route::prefix('api')->name('api.')->middleware('throttle:120,1')->group(function (): void {
        Route::get('quarantine', QuarantineIndexController::class)->name('quarantine.index');
        Route::post('quarantine/bulk-resolve', BulkResolveQuarantineController::class)->name('quarantine.bulk-resolve');
        Route::post('quarantine/{record}/resolve', ResolveQuarantineController::class)->whereNumber('record')->name('quarantine.resolve');
        Route::post('assessments/{assessment}/flags/{code}/override', OverrideSoftFlagController::class)
            ->whereNumber('assessment')->name('assessments.flags.override');

        Route::get('scoring-rules', [ScoringRuleController::class, 'index'])->name('scoring-rules.index');
        Route::post('scoring-rules', [ScoringRuleController::class, 'store'])->name('scoring-rules.store');
        Route::post('scoring-rules/{version}/preview', [ScoringRuleController::class, 'preview'])->whereNumber('version')->name('scoring-rules.preview');
        Route::post('scoring-rules/{version}/publish', [ScoringRuleController::class, 'publish'])->whereNumber('version')->name('scoring-rules.publish');
        Route::get('rounds', [RoundController::class, 'index'])->name('rounds.index');
        Route::post('rounds', [RoundController::class, 'store'])->name('rounds.store');
        Route::patch('rounds/{round}', [RoundController::class, 'update'])->whereNumber('round')->name('rounds.update');
        Route::post('rounds/{round}/close', [RoundController::class, 'close'])->whereNumber('round')->name('rounds.close');
        Route::post('rounds/{round}/reopen', [RoundController::class, 'reopen'])->whereNumber('round')->name('rounds.reopen');
        Route::post('rounds/{round}/rescore', [RescoreController::class, 'store'])->whereNumber('round')->name('rounds.rescore');
        Route::get('rescores/{batchId}', [RescoreController::class, 'show'])->where('batchId', '[A-Za-z0-9-]+')->name('rescores.show');
    });
});
