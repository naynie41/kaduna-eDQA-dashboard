<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Inside the protected group (routes/web.php). Password change and 2FA management only:
// accounts are managed by artisan commands (SECURITY.md §2).
Route::redirect('settings', '/settings/security');

Route::get('settings/security', [SecurityController::class, 'edit'])
    ->middleware(RequirePassword::class)
    ->name('security.edit');

Route::put('settings/password', [SecurityController::class, 'update'])
    ->middleware('throttle:6,1')
    ->name('user-password.update');
