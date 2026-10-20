<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/odk (ARCHITECTURE.md §5.4, SECURITY.md §6, D-54): a nudge to pull now, outside the
 * auth group and rate limited by the route.
 *
 * - Off (404) until ODK_WEBHOOK_SECRET is set: nothing reveals that the route exists.
 * - X-Edqa-Timestamp (Unix seconds) must be within edqa.odk.webhook.tolerance_seconds of now, and
 *   X-Edqa-Signature must be "sha256=" + HMAC-SHA256 of "{timestamp}.{raw body}" with the secret,
 *   compared with hash_equals. Signing the timestamp too means an old request cannot be replayed
 *   with a new one. Missing, wrong or stale: 401, and nothing happens.
 * - Accepted: it ONLY queues PullSubmissionsJob(PullTrigger::Webhook), at most one per
 *   debounce_seconds, and answers 202. The body is never read as data; the pull fetches from ODK
 *   as it always does. The scheduled pull runs regardless.
 */
final class OdkWebhookController extends Controller
{
    public const SIGNATURE = 'X-Edqa-Signature';

    public const TIMESTAMP = 'X-Edqa-Timestamp';

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('edqa.odk.webhook.secret');
        if (! is_string($secret) || $secret === '') {
            abort(404);
        }

        $timestamp = $request->header(self::TIMESTAMP);
        $signature = $request->header(self::SIGNATURE);
        if (! is_string($timestamp) || preg_match('/^\d{1,12}$/', $timestamp) !== 1 || ! is_string($signature) || $signature === '') {
            return $this->refuse('signature_missing');
        }
        if (abs(now()->getTimestamp() - (int) $timestamp) > (int) config('edqa.odk.webhook.tolerance_seconds', 300)) {
            return $this->refuse('timestamp_stale');
        }
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return $this->refuse('signature_invalid');
        }

        if (Cache::add('odk-webhook-pull', true, (int) config('edqa.odk.webhook.debounce_seconds', 60))) {
            PullSubmissionsJob::dispatch(PullTrigger::Webhook);
        }

        return response()->json(['message' => __('odk.webhook.queued')], 202);
    }

    private function refuse(string $code): JsonResponse
    {
        // Never the body or the headers' values: only why.
        Log::channel('edqa')->warning('ODK webhook refused', ['reason' => $code]);

        return response()->json(['code' => $code, 'message' => __("odk.webhook.{$code}")], 401);
    }
}
