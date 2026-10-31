<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminSourcesController;
use App\Http\Controllers\Assessments\CorrectAssessmentController;
use App\Http\Controllers\Attachments\ShowAttachmentController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\Dashboard\DashboardRedirectController;
use App\Http\Controllers\DevComponentsController;
use App\Http\Controllers\Facilities\CascadeExportController;
use App\Http\Controllers\Facilities\FacilityDetailController;
use App\Http\Controllers\Facilities\FacilityOptionsController;
use App\Http\Controllers\Odk\BackfillReportDownloadController;
use App\Http\Controllers\Quarantine\BulkResolveQuarantineController;
use App\Http\Controllers\Quarantine\DataIssuesPageController;
use App\Http\Controllers\Quarantine\OverrideSoftFlagController;
use App\Http\Controllers\Quarantine\QuarantineIndexController;
use App\Http\Controllers\Quarantine\ResolveQuarantineController;
use App\Http\Controllers\Quarantine\ShowQuarantinedRecordController;
use App\Http\Controllers\Rounds\RoundController;
use App\Http\Controllers\Scoring\RescoreController;
use App\Http\Controllers\Scoring\ScoringRuleController;
use App\Http\Controllers\Sources\PullRunController;
use App\Http\Controllers\Sources\SourceIndexController;
use App\Http\Controllers\Sources\SourcePullController;
use App\Http\Controllers\SourceWebhookController;
use App\Http\Middleware\ShareDashboardContext;
use Illuminate\Support\Facades\Route;

/*
| SECURITY.md §3: one protected group is the whole authorisation model. Outside it are only
| Fortify's login, 2FA challenge and password-reset screens, the source webhooks (each verified its
| own way, ARCHITECTURE.md §5.4) and GET /up. tests/Feature/Auth/RouteGuardTest.php enforces this for every registered route.
*/

// One per source (/webhooks/odk, /webhooks/kobo); a key not in config is a 404. 60 a minute per
// source and address (SECURITY.md §6).
Route::post('webhooks/{source}', SourceWebhookController::class)
    ->where('source', '[A-Za-z0-9_-]{1,64}')
    ->middleware('throttle:webhook')
    ->name('webhooks.source');

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

    // Administration (ARCHITECTURE.md §9.3, D-55): /admin opens the Data sources page, which
    // replaced the ODK pull page (/admin/odk still leads there). Rounds, Facility list and Scoring
    // rules are placeholders until their Phase 5 steps build them.
    Route::redirect('admin', '/admin/sources')->name('admin');
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('sources', AdminSourcesController::class)->name('sources');
        Route::permanentRedirect('odk', '/admin/sources')->name('odk');
        Route::get('data-issues', DataIssuesPageController::class)->name('data-issues');
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

        // The sources (§5.3, §8): pull, pull all and reconcile are 6 a minute per administrator and
        // per source (and action) on top of the group's 120 (SECURITY.md §8).
        Route::get('sources', SourceIndexController::class)->name('sources.index');
        Route::post('sources/pull-all', [SourcePullController::class, 'all'])->middleware('throttle:source-pull')->name('sources.pull-all');
        Route::post('sources/{key}/pull', [SourcePullController::class, 'store'])->where('key', '[A-Za-z0-9_-]{1,64}')->middleware('throttle:source-pull')->name('sources.pull');
        Route::post('sources/{key}/reconcile', [SourcePullController::class, 'reconcile'])->where('key', '[A-Za-z0-9_-]{1,64}')->middleware('throttle:source-pull')->name('sources.reconcile');
        Route::get('pulls/{run}', PullRunController::class)->whereNumber('run')->name('pulls.show');
        Route::get('backfill-reports/{file}', BackfillReportDownloadController::class)
            ->middleware('signed')->where('file', '[A-Za-z0-9.-]+')->name('backfill-reports.show');

        // The ODK cascading-select media (§5.1): generated on request, fetched through a signed link.
        Route::post('facility-list/export-cascade', [CascadeExportController::class, 'store'])->name('facility-list.cascade.store');
        Route::get('facility-list/export-cascade/{file}', [CascadeExportController::class, 'download'])
            ->middleware('signed')->where('file', '[A-Za-z0-9.-]+')->name('facility-list.cascade.download');
        Route::get('quarantine', QuarantineIndexController::class)->name('quarantine.index');
        Route::get('quarantine/{record}', ShowQuarantinedRecordController::class)->whereNumber('record')->name('quarantine.show');
        Route::get('facility-options', FacilityOptionsController::class)->name('facility-options');
        Route::post('quarantine/bulk-resolve', BulkResolveQuarantineController::class)->name('quarantine.bulk-resolve');
        Route::post('quarantine/{record}/resolve', ResolveQuarantineController::class)->whereNumber('record')->name('quarantine.resolve');
        Route::post('assessments/{assessment}/corrections', CorrectAssessmentController::class)->whereNumber('assessment')->name('assessments.corrections.store');
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
