<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// SECURITY.md §3, §7: no public read route, and stored files are only ever served through
// authenticated app routes. Laravel's local-disk "serve" feature would add an unauthenticated
// signed-URL route for storage/app/private (GET and PUT); it must stay off. The full
// route-guard test arrives with authentication in build step 5.
it('does not serve the local disk over an unauthenticated route', function (): void {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local.upload'))->toBeFalse()
        ->and(config('filesystems.disks.local.serve'))->toBeFalse();
});
