<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

use App\Domain\Ingestion\Exceptions\OdkAuthFailed;
use App\Domain\Ingestion\Exceptions\OdkNotConfigured;
use App\Domain\Ingestion\Exceptions\OdkUnexpectedResponse;
use App\Domain\Ingestion\Exceptions\OdkUnreachable;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * HTTP to ODK Central (ARCHITECTURE.md §5.2, SECURITY.md §6). Read-only: GETs, plus the session
 * POST.
 *
 * - https only, TLS verified (fail closed); connect timeout 10 s, 60 s a request.
 * - A session token from SessionTokenCache; on a 401 the token is dropped, a new session made and
 *   the request sent once more; a second 401 is OdkAuthFailed.
 * - 429, 5xx and connection errors are retried (3 attempts), waiting base × 2^n ms or the server's
 *   Retry-After, at most max_ms; any other 4xx is not retried. Waits go through Sleep, so tests
 *   fake the clock.
 * - Failures are typed: OdkNotConfigured, OdkAuthFailed, OdkUnreachable, OdkUnexpectedResponse.
 * - Logs on the edqa channel: method, endpoint (no query values), status, attempt, time. Never the
 *   password, the token or a payload.
 */
final class OdkCentralClient
{
    public function __construct(
        private readonly OdkConfig $config,
        private readonly SessionTokenCache $tokens,
    ) {}

    public function config(): OdkConfig
    {
        return $this->config;
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * An authenticated GET. $path is under the server ("/v1/projects/7/forms/dqa.svc/Submissions");
     * $query is encoded here (RFC 3986). With $sink the body is streamed to that file.
     *
     * @param  array<string, string|int>  $query
     *
     * @throws OdkNotConfigured|OdkAuthFailed|OdkUnreachable|OdkUnexpectedResponse
     */
    public function get(string $path, array $query = [], ?string $sink = null): Response
    {
        $this->assertConfigured();
        $url = $this->config->url.$path.self::queryString($query);
        $send = function (string $token) use ($url, $sink, $path): Response {
            return $this->send('GET', $path, function () use ($url, $sink, $token): Response {
                $request = $this->http()->withToken($token);

                return ($sink === null ? $request : $request->sink($sink))->get($url);
            });
        };

        $response = $send($this->token());
        if ($response->status() === 401) {
            // The cached session ended early (a restart, a revoked token): one fresh session.
            $this->tokens->forget();
            $response = $send($this->token());
            if ($response->status() === 401) {
                throw OdkAuthFailed::credentials(401);
            }
        }
        if ($response->status() === 403) {
            throw OdkAuthFailed::forbidden(403);
        }
        if (! $response->successful()) {
            throw OdkUnexpectedResponse::forStatus($response->status(), self::endpoint($path));
        }

        return $response;
    }

    /**
     * A decoded JSON body, or OdkUnexpectedResponse when it is not JSON.
     *
     * @return array<array-key, mixed>
     */
    public static function json(Response $response, string $path): array
    {
        try {
            $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw OdkUnexpectedResponse::malformed($response->status(), self::endpoint($path));
        }

        return is_array($body) ? $body : throw OdkUnexpectedResponse::malformed($response->status(), self::endpoint($path));
    }

    private function assertConfigured(): void
    {
        if (! $this->config->isConfigured()) {
            throw new OdkNotConfigured($this->config->problems());
        }
    }

    private function token(): string
    {
        return $this->tokens->get(function (): array {
            $response = $this->send('POST', '/v1/sessions', fn (): Response => $this->http()->post($this->config->url.'/v1/sessions', [
                'email' => $this->config->email,
                'password' => $this->config->password,
            ]));
            if (in_array($response->status(), [400, 401, 403], true)) {
                throw OdkAuthFailed::credentials($response->status());
            }
            if (! $response->successful()) {
                throw OdkUnexpectedResponse::forStatus($response->status(), 'sessions');
            }
            $body = self::json($response, '/v1/sessions');
            $token = $body['token'] ?? null;
            try {
                $expiresAt = is_string($body['expiresAt'] ?? null) ? CarbonImmutable::parse($body['expiresAt']) : null;
            } catch (Throwable) {
                $expiresAt = null;
            }
            if (! is_string($token) || $token === '' || $expiresAt === null) {
                throw OdkUnexpectedResponse::malformed($response->status(), 'sessions');
            }
            Log::channel('edqa')->info('odk.session', ['expires_at' => $expiresAt->toIso8601String()]);

            return ['token' => $token, 'expires_at' => $expiresAt];
        });
    }

    /**
     * Sends with retries on 429, 5xx and connection errors; returns any other response.
     *
     * @param  Closure(): Response  $request
     */
    private function send(string $method, string $path, Closure $request): Response
    {
        $endpoint = self::endpoint($path);
        $attempts = $this->config->attempts;
        for ($attempt = 1; ; $attempt++) {
            $started = hrtime(true);
            try {
                $response = $request();
            } catch (ConnectionException) {
                Log::channel('edqa')->warning('odk.request', ['method' => $method, 'endpoint' => $endpoint, 'error' => 'connection', 'attempt' => $attempt]);
                if ($attempt >= $attempts) {
                    throw OdkUnreachable::connection($attempts);
                }
                $this->wait($attempt, null, $endpoint, 'connection');

                continue;
            }

            $status = $response->status();
            Log::channel('edqa')->info('odk.request', [
                'method' => $method, 'endpoint' => $endpoint, 'status' => $status, 'attempt' => $attempt,
                'ms' => (int) ((hrtime(true) - $started) / 1e6),
            ]);
            if ($status !== 429 && $status < 500) {
                return $response;
            }
            if ($attempt >= $attempts) {
                throw OdkUnreachable::server($status, $attempts);
            }
            $this->wait($attempt, $response->header('Retry-After'), $endpoint, (string) $status);
        }
    }

    /** Exponential backoff, or the server's Retry-After (seconds or an HTTP date), capped at max_ms. */
    private function wait(int $attempt, ?string $retryAfter, string $endpoint, string $reason): void
    {
        $ms = $this->config->baseMs * 2 ** ($attempt - 1);
        if ($retryAfter !== null && $retryAfter !== '') {
            if (ctype_digit($retryAfter)) {
                $ms = (int) $retryAfter * 1000;
            } else {
                try {
                    $ms = max(0, (int) CarbonImmutable::now()->diffInMilliseconds(CarbonImmutable::parse($retryAfter), false));
                } catch (Throwable) {
                    // An unreadable Retry-After: keep the backoff.
                }
            }
        }
        $ms = min($ms, $this->config->maxMs);
        Log::channel('edqa')->warning('odk.retry', ['endpoint' => $endpoint, 'reason' => $reason, 'attempt' => $attempt, 'wait_ms' => $ms]);
        Sleep::for($ms)->milliseconds();
    }

    private function http(): PendingRequest
    {
        return Http::withOptions(['verify' => true])
            ->connectTimeout($this->config->connectTimeout)
            ->timeout($this->config->timeout)
            ->acceptJson()
            ->withUserAgent('kaduna-edqa');
    }

    /** @param  array<string, string|int>  $query */
    private static function queryString(array $query): string
    {
        if ($query === []) {
            return '';
        }

        return '?'.implode('&', array_map(fn (string $key, string|int $value): string => rawurlencode($key).'='.rawurlencode((string) $value), array_keys($query), $query));
    }

    /** The endpoint without project, form or instance IDs' values, for logs and messages. */
    private static function endpoint(string $path): string
    {
        return (string) preg_replace(
            ['#/projects/[^/]+#', '#/forms/[^/.]+#', '#/submissions/[^/]+#', '#/attachments/[^/]+#'],
            ['/projects/{project}', '/forms/{form}', '/submissions/{instance}', '/attachments/{name}'],
            $path,
        );
    }
}
