<?php

declare(strict_types=1);

use App\Http\Controllers\SourceWebhookController;
use Illuminate\Support\Facades\Route;

/*
| The source webhooks (SECURITY.md §6), outside the web group: no session, no cookies, no CSRF.
| One per source (/webhooks/odk, /webhooks/kobo); a key not in config is a 404. Each is verified its
| own way (ARCHITECTURE.md §5.4) and only enqueues a pull. 60 a minute per source and address.
*/

Route::post('webhooks/{source}', SourceWebhookController::class)
    ->where('source', '[A-Za-z0-9_-]{1,64}')
    ->middleware('throttle:webhook')
    ->name('webhooks.source');
