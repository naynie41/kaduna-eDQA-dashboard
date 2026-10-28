<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Kobo;

/**
 * KoboToolbox's settings (edqa.sources.kobo, SECURITY.md §6), enough to say whether the source is
 * configured. The adapter that uses them is Phase 2C step 4. The token is account-wide, so it
 * belongs to a dedicated read-only account, and is never shown.
 */
final readonly class KoboConfig
{
    private function __construct(
        public ?string $url,
        #[\SensitiveParameter] public ?string $token,
        public ?string $assetUid,
    ) {}

    /** @param  array<array-key, mixed>  $kobo  config('edqa.sources.kobo') */
    public static function fromArray(array $kobo): self
    {
        $text = fn (string $key): ?string => is_scalar($kobo[$key] ?? null) && trim((string) $kobo[$key]) !== '' ? trim((string) $kobo[$key]) : null;
        $url = $text('url');

        return new self($url === null ? null : rtrim($url, '/'), $text('token'), $text('asset_uid'));
    }

    public static function fromConfig(): self
    {
        $kobo = config('edqa.sources.kobo');

        return self::fromArray(is_array($kobo) ? $kobo : []);
    }

    /**
     * Env keys to set, in .env.example order, and "KOBO_BASE_URL (https)" for a URL that is not
     * https. Empty when configured. Never the values.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        foreach (['KOBO_BASE_URL' => $this->url, 'KOBO_API_TOKEN' => $this->token, 'KOBO_ASSET_UID' => $this->assetUid] as $key => $value) {
            if ($value === null) {
                $problems[] = $key;
            }
        }
        if ($this->url !== null && ! str_starts_with(strtolower($this->url), 'https://')) {
            $problems[] = 'KOBO_BASE_URL (https)';
        }

        return $problems;
    }

    public function isConfigured(): bool
    {
        return $this->problems() === [];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [...get_object_vars($this), 'token' => $this->token === null ? null : '[redacted]'];
    }
}
