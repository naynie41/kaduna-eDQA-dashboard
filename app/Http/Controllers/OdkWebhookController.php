<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * POST /webhooks/odk. A stub until ingestion (build step 8), which adds HMAC signature checks
 * and dispatches a pull; the webhook body is never trusted as data (ARCHITECTURE.md §5.4).
 */
final class OdkWebhookController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['message' => __('common.not_implemented')], 501);
    }
}
