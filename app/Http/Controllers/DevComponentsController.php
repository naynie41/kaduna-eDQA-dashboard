<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /dev/components: every base component in each state, for reviewing the styling against the
 * prototype. Exists only in the local environment (404 anywhere else) and sits inside the auth
 * group like every other page. Sample values only; nothing is read from the database.
 */
final class DevComponentsController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(app()->environment('local'), 404);

        return Inertia::render('dev/components');
    }
}
