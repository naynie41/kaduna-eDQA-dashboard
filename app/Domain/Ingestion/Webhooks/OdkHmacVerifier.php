<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Webhooks;

use Illuminate\Http\Request;

/**
 * ODK's webhook (D-54), unchanged from POST /webhooks/odk: X-Edqa-Timestamp (Unix seconds) must be
 * within edqa.odk.webhook.tolerance_seconds of now, and X-Edqa-Signature must be "sha256=" +
 * HMAC-SHA256 of "{timestamp}.{raw body}" with ODK_WEBHOOK_SECRET, compared with hash_equals.
 * Signing the timestamp too means an old request cannot be replayed with a new one.
 */
final class OdkHmacVerifier implements WebhookVerifier
{
    public const SIGNATURE = 'X-Edqa-Signature';

    public const TIMESTAMP = 'X-Edqa-Timestamp';

    public function enabled(): bool
    {
        return $this->secret() !== null;
    }

    public function refusal(Request $request): ?string
    {
        $secret = $this->secret();
        $timestamp = $request->header(self::TIMESTAMP);
        $signature = $request->header(self::SIGNATURE);
        if ($secret === null || ! is_string($timestamp) || preg_match('/^\d{1,12}$/', $timestamp) !== 1 || ! is_string($signature) || $signature === '') {
            return 'signature_missing';
        }
        if (abs(now()->getTimestamp() - (int) $timestamp) > (int) config('edqa.odk.webhook.tolerance_seconds', 300)) {
            return 'timestamp_stale';
        }
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature) ? null : 'signature_invalid';
    }

    public function debounceSeconds(): int
    {
        return (int) config('edqa.odk.webhook.debounce_seconds', 60);
    }

    private function secret(): ?string
    {
        $secret = config('edqa.odk.webhook.secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
