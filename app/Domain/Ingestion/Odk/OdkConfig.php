<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

/**
 * ODK Central settings from config/edqa.php 'odk' (env is read there only). Configured means all
 * five values are present and the URL is https (SECURITY.md §6: https only, fail closed).
 *
 * The one exception is the local mock server (docker/odk-mock): plain http is accepted when
 * ODK_ALLOW_INSECURE_LOCAL is true **and** the app runs in the local environment. The flag set in
 * any other environment is itself a problem, so a production misconfiguration fails loudly.
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
        public bool $allowInsecureLocal,
        public string $environment,
    ) {}

    /** @param  array<array-key, mixed>  $odk  config('edqa.odk') */
    public static function fromArray(array $odk, string $environment = 'production'): self
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
            allowInsecureLocal: ($odk['allow_insecure_local'] ?? false) === true,
            environment: $environment,
        );
    }

    /** The adapter's tuning (edqa.odk) with the source itself (edqa.sources.odk: URL, credentials, form, page size). */
    public static function fromConfig(): self
    {
        $odk = config('edqa.odk');
        $source = config('edqa.sources.odk');

        return self::fromArray([...(is_array($odk) ? $odk : []), ...(is_array($source) ? $source : [])], (string) app()->environment());
    }

    /**
     * What stops the client: env keys to set, in .env.example order; "ODK_CENTRAL_URL (https)" for a
     * URL that is not https (unless the local mock is allowed); "ODK_ALLOW_INSECURE_LOCAL (local
     * only)" when that flag is set outside local. Empty when configured. Never the values themselves.
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
        $local = $this->environment === 'local';
        if ($this->allowInsecureLocal && ! $local) {
            $problems[] = 'ODK_ALLOW_INSECURE_LOCAL (local only)';
        }
        $https = $this->url !== null && str_starts_with(strtolower($this->url), 'https://');
        $localHttp = $this->url !== null && $this->allowInsecureLocal && $local && str_starts_with(strtolower($this->url), 'http://');
        if ($this->url !== null && ! $https && ! $localHttp) {
            $problems[] = 'ODK_CENTRAL_URL (https)';
        }

        return $problems;
    }

    public function isConfigured(): bool
    {
        return $this->problems() === [];
    }

    /** The form the cursor follows (source_syncs.form_ref): "project/form". */
    public function formRef(): string
    {
        return "{$this->projectId}/{$this->formId}";
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
