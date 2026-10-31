<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Webhooks;

use Illuminate\Http\Request;

/**
 * Kobo's webhook (ARCHITECTURE.md §5.4, SECURITY.md §6). Kobo's REST Services cannot sign a
 * request, so the service is set up to send a long random token in a custom header,
 * X-EDQA-Webhook-Token, compared with hash_equals against KOBO_WEBHOOK_TOKEN.
 *
 * A token shorter than MIN_LENGTH (32) counts as not set, so the route stays off (404): a short
 * token could be guessed, and the rate limit alone is not the control.
 */
final class KoboTokenVerifier implements WebhookVerifier
{
    public const HEADER = 'X-EDQA-Webhook-Token';

    public const MIN_LENGTH = 32;

    public function enabled(): bool
    {
        return $this->token() !== null;
    }

    public function refusal(Request $request): ?string
    {
        $token = $this->token();
        $sent = $request->header(self::HEADER);
        if ($token === null || ! is_string($sent) || $sent === '') {
            return 'token_missing';
        }

        return hash_equals($token, $sent) ? null : 'token_invalid';
    }

    public function debounceSeconds(): int
    {
        return (int) config('edqa.kobo.webhook.debounce_seconds', 60);
    }

    private function token(): ?string
    {
        $token = config('edqa.kobo.webhook.token');

        return is_string($token) && strlen($token) >= self::MIN_LENGTH ? $token : null;
    }
}
