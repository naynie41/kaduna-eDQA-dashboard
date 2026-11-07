<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminFacilityController;
use App\Http\Controllers\Admin\AdminSourcesController;
use App\Http\Controllers\Assessments\AssessmentStatusController;
use App\Http\Controllers\Assessments\CorrectAssessmentController;
use App\Http\Controllers\Attachments\ShowAttachmentController;
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
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Sources\PullRunController;
use App\Http\Controllers\Sources\SourceIndexController;
use App\Http\Controllers\Sources\SourcePullController;
use App\Http\Controllers\Submissions\ReviewDeletionController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
| The admin area (SECURITY.md §3, D-76): everything that reads private data or changes anything,
| under /admin, named admin.*. Registered in the web group with ['admin.ip', 'auth'] (bootstrap/
| app.php): the optional allow-list first, so an address outside it gets 404; then a signed-in
| administrator, password only (D-84). A guest is sent to /login.
*/

// The pages (ARCHITECTURE.md §9.3, D-55): /admin opens Data sources (/admin/odk still leads there).
// Rounds, Facility list and Scoring rules are placeholders until their Phase 5 steps build them.
Route::redirect('/', '/admin/sources')->name('home');
Route::get('sources', AdminSourcesController::class)->name('sources');
Route::permanentRedirect('odk', '/admin/sources')->name('odk');
Route::get('data-issues', DataIssuesPageController::class)->name('data-issues');
Route::inertia('rounds', 'admin/rounds')->name('rounds');
Route::inertia('facilities', 'admin/facilities')->name('facilities');
// The admin facility view (ARCHITECTURE.md §9.2.7): every round, flags, attachments, the audit
// trail, the source and its link, and corrections. ?round= picks the round.
Route::get('facilities/{facility}', AdminFacilityController::class)->whereNumber('facility')->name('facilities.show');
Route::inertia('scoring-rules', 'admin/scoring-rules')->name('scoring-rules');

// Password change (SECURITY.md §2): accounts themselves are managed by artisan commands.
Route::redirect('settings', '/admin/settings/security')->name('settings');
Route::get('settings/security', [SecurityController::class, 'edit'])
    ->middleware(RequirePassword::class)
    ->name('security.edit');
Route::put('settings/password', [SecurityController::class, 'update'])
    ->middleware('throttle:6,1')
    ->name('user-password.update');

// Component gallery for design review; 404 outside the local environment.
Route::get('dev/components', DevComponentsController::class)->name('dev.components');

// Source attachments, streamed from the private disk (SECURITY.md §7, evidence: never public). The
// name is looked up exactly among the submission's fetched files; a slash never reaches the controller.
Route::get('attachments/{submission}/{filename}', ShowAttachmentController::class)
    ->whereNumber('submission')->where('filename', '[^/]+')
    ->middleware('throttle:120,1')->name('attachments.show');

// JSON for admin interactivity (ARCHITECTURE.md §8): /admin/api, same session, same guard. 120/min
// per user (SECURITY.md §8). /api/public is the public dashboard's, not this.
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

    // The cascading-select media (§5.1): generated on request, fetched through a signed link.
    Route::post('facility-list/export-cascade', [CascadeExportController::class, 'store'])->name('facility-list.cascade.store');
    Route::get('facility-list/export-cascade/{file}', [CascadeExportController::class, 'download'])
        ->middleware('signed')->where('file', '[A-Za-z0-9.-]+')->name('facility-list.cascade.download');
    Route::get('quarantine', QuarantineIndexController::class)->name('quarantine.index');
    Route::get('quarantine/{record}', ShowQuarantinedRecordController::class)->whereNumber('record')->name('quarantine.show');
    Route::get('facility-options', FacilityOptionsController::class)->name('facility-options');
    Route::post('quarantine/bulk-resolve', BulkResolveQuarantineController::class)->name('quarantine.bulk-resolve');
    Route::post('quarantine/{record}/resolve', ResolveQuarantineController::class)->whereNumber('record')->name('quarantine.resolve');
    Route::post('submissions/{submission}/deletion-review', ReviewDeletionController::class)->whereNumber('submission')->name('submissions.deletion-review');
    Route::post('assessments/{assessment}/withdraw', [AssessmentStatusController::class, 'withdraw'])->whereNumber('assessment')->name('assessments.withdraw');
    Route::post('assessments/{assessment}/restore', [AssessmentStatusController::class, 'restore'])->whereNumber('assessment')->name('assessments.restore');
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
