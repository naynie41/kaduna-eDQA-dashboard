<?php

declare(strict_types=1);

namespace App\Http\Controllers\Odk;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/odk/status (ARCHITECTURE.md §8): is ODK Central configured and answering, the sync
 * state the status bar shows (last success, minutes since, last error), the cursors, the pull in
 * progress and the last one, and each open round's accepted, flagged and quarantined counts.
 */
final class OdkStatusController extends Controller
{
    public function __invoke(OdkStatusPayload $status): JsonResponse
    {
        return response()->json(['data' => $status->build()]);
    }
}
