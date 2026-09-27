<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// No public read routes (SECURITY.md §3). The two-factor.confirmed middleware joins this
// group in build step 5.
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('/', 'home')->name('home');
});

require __DIR__.'/settings.php';
