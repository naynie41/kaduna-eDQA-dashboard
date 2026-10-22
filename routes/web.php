<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminOdkController;
use App\Http\Controllers\Attachments\ShowAttachmentController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\Dashboard\DashboardRedirectController;
use App\Http\Controllers\DevComponentsController;
use App\Http\Controllers\Facilities\CascadeExportController;
use App\Http\Controllers\Facilities\FacilityDetailController;
use App\Http\Controllers\Odk\BackfillReportDownloadController;
use App\Http\Controllers\Odk\OdkPullController;
use App\Http\Controllers\Odk\OdkStatusController;
use App\Http\Controllers\OdkWebhookController;
use App\Http\Controllers\Quarantine\BulkResolveQuarantineController;
use App\Http\Controllers\Quarantine\OverrideSoftFlagController;
use App\Http\Controllers\Quarantine\QuarantineIndexController;
use App\Http\Controllers\Quarantine\ResolveQuarantineController;
use App\Http\Controllers\Rounds\RoundController;
use App\Http\Controllers\Scoring\RescoreController;
use App\Http\Controllers\Scoring\ScoringRuleController;
use App\Http\Middleware\ShareDashboardContext;
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
    // The dashboard (ARCHITECTURE.md §9.2): `/` and `/dashboard` open Home. Filters live in the
    // query string, resolved once per request by ShareDashboardContext.
    Route::get('/', DashboardRedirectController::class)->name('home');
    Route::get('dashboard', DashboardRedirectController::class)->name('dashboard');
    Route::prefix('dashboard')->name('dashboard.')->middleware(ShareDashboardContext::class)->group(function (): void {
        Route::get('home', Dashboard\HomeController::class)->name('home');
        Route::get('facility', Dashboard\FacilityController::class)->name('facility');
        Route::get('lga', Dashboard\LgaController::class)->name('lga');
        Route::get('monitor', Dashboard\MonitorController::class)->name('monitor');
        Route::get('visuals', Dashboard\VisualsController::class)->name('visuals');
        Route::get('plan', Dashboard\PlanController::class)->name('plan');
    });

    // Administration (ARCHITECTURE.md §9.3, D-55): /admin opens the ODK pull page. The five pages,
    // each a placeholder until its Phase 5 step builds it.
    Route::redirect('admin', '/admin/odk')->name('admin');
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('odk', AdminOdkController::class)->name('odk');
        Route::inertia('data-issues', 'admin/data-issues')->name('data-issues');
        Route::inertia('rounds', 'admin/rounds')->name('rounds');
        Route::inertia('facilities', 'admin/facilities')->name('facilities');
        Route::inertia('scoring-rules', 'admin/scoring-rules')->name('scoring-rules');
    });

    // Component gallery for design review; 404 outside the local environment.
    Route::get('dev/components', DevComponentsController::class)->name('dev.components');

    // ODK attachments, streamed from the private odk disk (SECURITY.md §7). The name is looked up
    // exactly among the submission's fetched files; a slash never reaches the controller.
    Route::get('attachments/{submission}/{filename}', ShowAttachmentController::class)
        ->whereNumber('submission')->where('filename', '[^/]+')
        ->middleware('throttle:120,1')->name('attachments.show');

    require __DIR__.'/settings.php';

    // JSON for admin interactivity (ARCHITECTURE.md §8): same session, same guard. 120/min per
    // user (SECURITY.md §8).
    Route::prefix('api')->name('api.')->middleware('throttle:120,1')->group(function (): void {
        Route::get('facilities/{facility}', FacilityDetailController::class)->whereNumber('facility')->name('facilities.show');

        // ODK (§5.3, §8): "Pull now" is 6 a minute per administrator on top of the group's 120 (SECURITY.md §8).
        Route::post('odk/pull', [OdkPullController::class, 'store'])->middleware('throttle:odk-pull')->name('odk.pull.store');
        Route::get('odk/pull/{run}', [OdkPullController::class, 'show'])->whereNumber('run')->name('odk.pull.show');
        Route::get('odk/status', OdkStatusController::class)->name('odk.status');
        Route::get('odk/backfill-report/{file}', BackfillReportDownloadController::class)
            ->middleware('signed')->where('file', '[A-Za-z0-9.-]+')->name('odk.backfill-report');

        // The ODK cascading-select media (§5.1): generated on request, fetched through a signed link.
        Route::post('facility-list/export-cascade', [CascadeExportController::class, 'store'])->name('facility-list.cascade.store');
        Route::get('facility-list/export-cascade/{file}', [CascadeExportController::class, 'download'])
            ->middleware('signed')->where('file', '[A-Za-z0-9.-]+')->name('facility-list.cascade.download');
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
