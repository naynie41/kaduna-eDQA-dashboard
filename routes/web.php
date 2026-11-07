<?php

declare(strict_types=1);

use App\Http\Controllers\Public;
use App\Http\Controllers\Public\OldDashboardUrlController;
use App\Http\Middleware\ShareDashboardContext;
use Illuminate\Support\Facades\Route;

/*
| SECURITY.md §3: the routes reachable signed out. Here, the public dashboard (read-only, rate
| limited, closed rounds only, D-76/D-77). Fortify's sign-in and reset screens are registered by
| Fortify (config/fortify.php: allow-list, no remember-me) and GET /up by the framework. The admin
| area is routes/admin.php, the source webhooks routes/webhooks.php. Every route is classified by
| tests/Feature/Auth/RouteGuardTest.php; an unclassified one fails it.
*/

Route::middleware(['public.readonly', 'throttle:public'])->name('public.')->group(function (): void {
    // The dashboard (ARCHITECTURE.md §9.0, §9.2). Filters live in the query string, resolved once
    // per request by ShareDashboardContext.
    Route::middleware(ShareDashboardContext::class)->group(function (): void {
        Route::get('/', Public\HomeController::class)->name('home');
        Route::get('facilities', Public\FacilityController::class)->name('facilities');
        Route::get('lgas', Public\LgaController::class)->name('lgas');
        Route::get('monitor', Public\MonitorController::class)->name('monitor');
        Route::get('visuals', Public\VisualsController::class)->name('visuals');
        Route::get('plan', Public\PlanController::class)->name('plan');
    });

    // The public JSON (ARCHITECTURE.md §8): the same filters as the pages, closed rounds only for a
    // visitor, public resources only (D-83).
    Route::prefix('api/public')->name('api.')->group(function (): void {
        Route::get('aggregates', Public\Api\AggregatesController::class)->name('aggregates');
        Route::get('facilities', Public\Api\FacilitiesController::class)->name('facilities.index');
        Route::get('facilities/{facility}', Public\Api\FacilityDetailController::class)->whereNumber('facility')->name('facilities.show');
        Route::get('status', Public\Api\StatusController::class)->name('status');
    });

    // The signed-in dashboard's old URLs: 301 to the public ones, filters kept.
    Route::get('dashboard', OldDashboardUrlController::class)->name('old');
    Route::get('dashboard/{page}', OldDashboardUrlController::class)
        ->whereIn('page', array_values(array_filter(array_keys(OldDashboardUrlController::PATHS))))
        ->name('old.page');
});
