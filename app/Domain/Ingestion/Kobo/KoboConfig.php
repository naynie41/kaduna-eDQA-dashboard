<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Kobo;

/**
 * KoboToolbox's settings (SECURITY.md §6): the source (edqa.sources.kobo: base URL, token, asset
 * UID, page size) and how the client talks to it (edqa.kobo: timeouts, retries, the local mock).
 * Read only here; the client never reads env. Any of the three missing, or a URL that is not https,
 * means "not configured", and nothing is sent. The token is account-wide, so it belongs to a
 * dedicated read-only account, and is never shown (__debugInfo).
 */
final readonly class KoboConfig
{
    private function __construct(
        public ?string $url,
        #[\SensitiveParameter] public ?string $token,
        public ?string $assetUid,
        public int $pageSize,
        public int $connectTimeout,
        public int $timeout,
        public int $attempts,
        public int $baseMs,
        public int $maxMs,
        public bool $allowInsecureLocal,
        public string $environment,
    ) {}

    /** The documented maximum page size (KPI API v2: "cannot exceed 1000, unless changed by server configuration"). */
    public const MAX_PAGE_SIZE = 1000;

    /**
     * @param  array<array-key, mixed>  $kobo  edqa.kobo laid under edqa.sources.kobo
     */
    public static function fromArray(array $kobo, string $environment = 'production'): self
    {
        $text = fn (string $key): ?string => is_scalar($kobo[$key] ?? null) && trim((string) $kobo[$key]) !== '' ? trim((string) $kobo[$key]) : null;
        $int = fn (mixed $value, int $default): int => is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
        $timeouts = is_array($kobo['timeouts'] ?? null) ? $kobo['timeouts'] : [];
        $retry = is_array($kobo['retry'] ?? null) ? $kobo['retry'] : [];
        $url = $text('url');

        return new self(
            url: $url === null ? null : rtrim($url, '/'),
            token: is_string($kobo['token'] ?? null) && trim($kobo['token']) !== '' ? trim($kobo['token']) : null,
            assetUid: $text('asset_uid'),
            pageSize: min(self::MAX_PAGE_SIZE, $int($kobo['page_size'] ?? null, 500)),
            connectTimeout: $int($timeouts['connect'] ?? null, 10),
            timeout: $int($timeouts['total'] ?? null, 60),
            attempts: $int($retry['attempts'] ?? null, 3),
            baseMs: $int($retry['base_ms'] ?? null, 1000),
            maxMs: $int($retry['max_ms'] ?? null, 30000),
            allowInsecureLocal: ($kobo['allow_insecure_local'] ?? false) === true,
            environment: $environment,
        );
    }

    public static function fromConfig(): self
    {
        $tuning = config('edqa.kobo');
        $source = config('edqa.sources.kobo');

        return self::fromArray([...(is_array($tuning) ? $tuning : []), ...(is_array($source) ? $source : [])], (string) app()->environment());
    }

    /**
     * Env keys to set, in .env.example order; "KOBO_BASE_URL (https)" for a URL that is not https
     * (unless the local mock is allowed); "KOBO_ALLOW_INSECURE_LOCAL (local only)" when that flag
     * is set outside local. Empty when configured. Never the values.
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
        $local = $this->environment === 'local';
        if ($this->allowInsecureLocal && ! $local) {
            $problems[] = 'KOBO_ALLOW_INSECURE_LOCAL (local only)';
        }
        if ($this->url !== null && ! $this->schemeAllowed($this->url)) {
            $problems[] = 'KOBO_BASE_URL (https)';
        }

        return $problems;
    }

    public function isConfigured(): bool
    {
        return $this->problems() === [];
    }

    /** The configured server's host (lower case), what an attachment URL must match. */
    public function host(): ?string
    {
        $host = $this->url === null ? null : parse_url($this->url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }

    /** The configured server's port, null for the scheme's default. */
    public function port(): ?int
    {
        $port = $this->url === null ? null : parse_url($this->url, PHP_URL_PORT);

        return is_int($port) ? $port : null;
    }

    /** https; plain http only for the local mock, with the flag set, in APP_ENV=local. */
    public function schemeAllowed(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' || ($scheme === 'http' && $this->allowInsecureLocal && $this->environment === 'local');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [...get_object_vars($this), 'token' => $this->token === null ? null : '[redacted]'];
    }
}
