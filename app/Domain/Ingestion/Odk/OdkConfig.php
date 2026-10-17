<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

/**
 * ODK Central settings from config/edqa.php 'odk' (env is read there only). Configured means all
 * five values are present and the URL is https (SECURITY.md §6: https only, fail closed).
 */
final readonly class OdkConfig
{
    private function __construct(
        public ?string $url,
        public ?string $email,
        #[\SensitiveParameter] public ?string $password,
        public ?string $projectId,
        public ?string $formId,
        public int $pageSize,
        public int $connectTimeout,
        public int $timeout,
        public int $attempts,
        public int $baseMs,
        public int $maxMs,
        public int $refreshMinutes,
    ) {}

    /** @param  array<array-key, mixed>  $odk  config('edqa.odk') */
    public static function fromArray(array $odk): self
    {
        $text = fn (string $key): ?string => is_scalar($odk[$key] ?? null) && trim((string) $odk[$key]) !== '' ? trim((string) $odk[$key]) : null;
        $int = fn (mixed $value, int $default): int => is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
        $timeouts = is_array($odk['timeouts'] ?? null) ? $odk['timeouts'] : [];
        $retry = is_array($odk['retry'] ?? null) ? $odk['retry'] : [];
        $url = $text('url');

        return new self(
            url: $url === null ? null : rtrim($url, '/'),
            email: $text('email'),
            password: is_string($odk['password'] ?? null) && $odk['password'] !== '' ? $odk['password'] : null,
            projectId: $text('project_id'),
            formId: $text('form_id'),
            pageSize: $int($odk['page_size'] ?? null, 500),
            connectTimeout: $int($timeouts['connect'] ?? null, 10),
            timeout: $int($timeouts['total'] ?? null, 60),
            attempts: $int($retry['attempts'] ?? null, 3),
            baseMs: $int($retry['base_ms'] ?? null, 1000),
            maxMs: $int($retry['max_ms'] ?? null, 30000),
            refreshMinutes: $int($odk['token_refresh_minutes'] ?? null, 30),
        );
    }

    public static function fromConfig(): self
    {
        $odk = config('edqa.odk');

        return self::fromArray(is_array($odk) ? $odk : []);
    }

    /**
     * What stops the client: env keys to set, in .env.example order, and "ODK_CENTRAL_URL (https)"
     * for a URL that is not https. Empty when configured. Never the values themselves.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        foreach (['ODK_CENTRAL_URL' => $this->url, 'ODK_CENTRAL_EMAIL' => $this->email, 'ODK_CENTRAL_PASSWORD' => $this->password, 'ODK_PROJECT_ID' => $this->projectId, 'ODK_FORM_ID' => $this->formId] as $key => $value) {
            if ($value === null) {
                $problems[] = $key;
            }
        }
        if ($this->url !== null && ! str_starts_with(strtolower($this->url), 'https://')) {
            $problems[] = 'ODK_CENTRAL_URL (https)';
        }

        return $problems;
    }

    public function isConfigured(): bool
    {
        return $this->problems() === [];
    }

    /**
     * A dump never shows the password.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [...get_object_vars($this), 'password' => $this->password === null ? null : '[redacted]'];
    }
}
