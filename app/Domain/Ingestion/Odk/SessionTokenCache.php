<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * The ODK Central session token (POST /v1/sessions), kept in the cache **encrypted** with the app
 * key until $refreshMinutes before it expires (SECURITY.md §6), so every pull and every worker
 * shares one session. Never logged. A token that cannot be decrypted (APP_KEY rotated) is dropped
 * and a new session made.
 */
final class SessionTokenCache
{
    public const KEY = 'edqa:odk:session';

    public function __construct(
        private readonly int $refreshMinutes,
    ) {}

    /**
     * The cached token, or a new one from $create, cached until refresh time.
     *
     * @param  Closure(): array{token: string, expires_at: CarbonImmutable}  $create
     */
    public function get(Closure $create): string
    {
        $cached = Cache::get(self::KEY);
        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (DecryptException) {
                $this->forget();
            }
        }

        ['token' => $token, 'expires_at' => $expiresAt] = $create();
        $refreshAt = $expiresAt->subMinutes($this->refreshMinutes);
        if ($refreshAt->isFuture()) {
            Cache::put(self::KEY, Crypt::encryptString($token), $refreshAt);
        }

        return $token;
    }

    public function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
