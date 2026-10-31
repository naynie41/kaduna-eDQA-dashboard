<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Webhooks;

use Illuminate\Http\Request;

/**
 * How one source proves a webhook came from it (ARCHITECTURE.md §5.4, SECURITY.md §6). A webhook
 * only ever nudges that source's pull; what it carries is never read as data.
 */
interface WebhookVerifier
{
    /** False while the secret is not set: the route then answers 404, as if it did not exist. */
    public function enabled(): bool;

    /** Null when the request is genuine; otherwise why not, a code from lang sources.webhook. */
    public function refusal(Request $request): ?string;

    /** Seconds within which a second accepted webhook queues nothing more. */
    public function debounceSeconds(): int;
}
