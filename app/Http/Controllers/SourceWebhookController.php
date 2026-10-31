<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Enums\SourceType;
use App\Domain\Ingestion\Exceptions\SourceRefused;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Ingestion\Webhooks\KoboTokenVerifier;
use App\Domain\Ingestion\Webhooks\OdkHmacVerifier;
use App\Domain\Ingestion\Webhooks\WebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/{source} (ARCHITECTURE.md §5.4, SECURITY.md §6): a nudge to pull one source now,
 * outside the auth group and rate limited per source by the route. Each source proves itself its
 * own way (OdkHmacVerifier, KoboTokenVerifier).
 *
 * - A key not in config, or a source whose secret is not set: 404, so nothing reveals the route.
 * - Not genuine: 401 with why (never the headers' values), and nothing happens.
 * - Genuine: it ONLY queues PullSubmissionsJob(source, Webhook) for that source, at most one per
 *   debounce window, and answers 202. A source switched off answers the same, and queues nothing.
 *   The body is never read as data; the pull fetches from the source as it always does, and the
 *   scheduled pull runs regardless.
 */
final class SourceWebhookController extends Controller
{
    public function __invoke(Request $request, SourceRegistry $sources, string $source): JsonResponse
    {
        try {
            $type = $sources->type($source);
        } catch (SourceRefused) {
            abort(404);
        }
        $verifier = self::verifier($type);
        if (! $verifier->enabled()) {
            abort(404);
        }

        $refusal = $verifier->refusal($request);
        if ($refusal !== null) {
            Log::channel('edqa')->warning('Source webhook refused', ['source' => $source, 'reason' => $refusal]);

            return response()->json(['code' => $refusal, 'message' => __("sources.webhook.{$refusal}")], 401);
        }

        if (in_array($source, $sources->enabled(), true) && Cache::add("webhook-pull:{$source}", true, $verifier->debounceSeconds())) {
            PullSubmissionsJob::dispatch($source, PullTrigger::Webhook);
        }

        return response()->json(['message' => __('sources.webhook.queued')], 202);
    }

    private static function verifier(SourceType $type): WebhookVerifier
    {
        return match ($type) {
            SourceType::OdkCentral => new OdkHmacVerifier,
            SourceType::Kobo => new KoboTokenVerifier,
        };
    }
}
