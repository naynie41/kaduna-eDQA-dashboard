<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Kobo;

use App\Domain\Ingestion\Exceptions\KoboAuthFailed;
use App\Domain\Ingestion\Exceptions\KoboNotConfigured;
use App\Domain\Ingestion\Exceptions\KoboUnexpectedResponse;
use App\Domain\Ingestion\Exceptions\KoboUnreachable;
use App\Domain\Ingestion\Exceptions\KoboUnsafeUrl;
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
 * HTTP to KoboToolbox's KPI API v2 (ARCHITECTURE.md §5.3, SECURITY.md §6). Read only.
 *
 * Verified against the KPI OpenAPI schema ("KoboToolbox Primary API", 2.0.0 (api_v2), OpenAPI
 * 3.1.0, from https://kf.kobotoolbox.org/api/v2/schema/?format=json, 28 October 2026) and
 * https://support.kobotoolbox.org/api.html:
 *
 * - Auth: components.securitySchemes.TokenAuth, header "Authorization: Token <token>". The token is
 *   account-wide: a dedicated account with view form and view submissions on the one asset (Q-18
 *   in the new numbering).
 * - Submissions: GET /api/v2/assets/{uid_asset}/data/ (operation api_v2_assets_data_list). Query
 *   parameters: format (json | xml | geojson), query ("Filter the results with search query", a
 *   MongoDB query document, the docs' example {"_submission_time": {"$gt": "2019-09-01T01:02:03"}},
 *   a time with no zone, which Kobo means as UTC), sort ("Sort the results by a field, e.g.
 *   {"_id":-1}"), start ("The initial index from which to return the results. Use with limit"),
 *   limit ("Number of results to return per page"; "Maximum results per page cannot exceed 1000,
 *   unless changed by server configuration"), fields; offset is a deprecated alias of start. The
 *   answer (PaginatedDataResponseList) is {count, next, previous, results}. The client pages with
 *   start and limit and never follows next, so no answer can point it at another host.
 * - A submission (DataResponse): _id, formhub/uuid, __version__, meta/instanceID, meta/rootUuid,
 *   meta/deprecatedID, meta/formVersions, _xform_id_string, _uuid, _attachments, _status,
 *   _geolocation, _submission_time, _validation_status, _submitted_by, _supplementalDetails. There
 *   is NO last-edited field: date_modified exists only on assets, versions, hooks and exports. So
 *   no filter can find edits (see KoboSource::changedSince).
 * - _attachments[]: download_url, download_large_url, download_medium_url, download_small_url,
 *   mimetype, media_file_basename, filename, uid, question_xpath, is_deleted.
 * - Asset: GET /api/v2/assets/{uid_asset}/ ("format" json | ssjson | xls | xml): name,
 *   owner__username, deployed_version_id, has_deployment, deployment__active, deployed_versions
 *   {count, next, previous, results}.
 * - Versions: GET /api/v2/assets/{uid_asset}/versions/ (start, limit) → paginated {uid, url,
 *   content_hash, date_deployed (a time, or false when never deployed), date_modified,
 *   version_number}.
 * - Account: GET /me/ → username.
 * - Rate limits: none documented for these endpoints. 429 is documented only on account endpoints
 *   ("Request was throttled. Expected available in N seconds."). 429 and 5xx are retried anyway,
 *   honouring Retry-After.
 * - Not confirmed by the docs (check against the client's server on credentials day): whether
 *   download_url answers with the file itself or redirects to file storage on another host. A
 *   redirect off the Kobo host is refused (download()), so such a server would need its storage
 *   host allowed here first.
 *
 * Every request: TLS verified, connect and total timeouts, the token only to the configured host.
 * Logs carry the endpoint without IDs and the status, never the token, a URL's query or a body.
 */
final class KoboClient
{
    /** Redirects followed on the Kobo host, at most, for one download. */
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly KoboConfig $config,
    ) {}

    public function config(): KoboConfig
    {
        return $this->config;
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * An authenticated GET of a path on the configured server ("/api/v2/assets/{uid}/data/");
     * $query is encoded here (RFC 3986).
     *
     * @param  array<string, string|int>  $query
     *
     * @throws KoboNotConfigured|KoboAuthFailed|KoboUnreachable|KoboUnexpectedResponse
     */
    public function get(string $path, array $query = []): Response
    {
        $this->assertConfigured();
        $url = $this->config->url.$path.self::queryString($query);
        $endpoint = self::endpoint($path);

        return self::checked($this->send('GET', $endpoint, fn (): Response => $this->http()->get($url)), $endpoint);
    }

    /**
     * Streams a file from a URL the source gave (an attachment's download_url) to $sink. Followed
     * only when it is https (or the local mock's http) and on the configured host and port;
     * redirects are followed only while they stay there (SECURITY.md §6, SSRF).
     *
     * @throws KoboNotConfigured|KoboUnsafeUrl|KoboAuthFailed|KoboUnreachable|KoboUnexpectedResponse
     */
    public function download(string $url, string $sink): Response
    {
        $this->assertConfigured();
        $this->guard($url, redirect: false);
        $endpoint = 'attachment';

        for ($hop = 0; ; $hop++) {
            $current = $url;
            $response = $this->send('GET', $endpoint, fn (): Response => $this->http()->withoutRedirecting()->sink($sink)->get($current));
            if (! $response->redirect()) {
                return self::checked($response, $endpoint);
            }
            if ($hop >= self::MAX_REDIRECTS) {
                throw KoboUnexpectedResponse::forStatus($response->status(), $endpoint);
            }
            $url = self::resolve($current, $response->header('Location'));
            $this->guard($url, redirect: true);
        }
    }

    /**
     * A decoded JSON object or list, or KoboUnexpectedResponse when the body is not JSON.
     *
     * @return array<array-key, mixed>
     */
    public static function json(Response $response, string $what): array
    {
        try {
            $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw KoboUnexpectedResponse::malformed($response->status(), $what);
        }

        return is_array($body) ? $body : throw KoboUnexpectedResponse::malformed($response->status(), $what);
    }

    private function assertConfigured(): void
    {
        if (! $this->config->isConfigured()) {
            throw new KoboNotConfigured($this->config->problems());
        }
    }

    /** Refuses a URL the token must not be sent to. */
    private function guard(string $url, bool $redirect): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        if (! is_array($parts) || ! $this->config->schemeAllowed($url) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)) {
            throw KoboUnsafeUrl::scheme();
        }
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : null;
        if ($host !== $this->config->host() || $port !== $this->config->port() || isset($parts['user']) || isset($parts['pass'])) {
            throw $redirect ? KoboUnsafeUrl::redirect($host) : KoboUnsafeUrl::host($host);
        }
    }

    /** 401 / 403 stop the source (no retry); any other non-2xx is unexpected. */
    private static function checked(Response $response, string $endpoint): Response
    {
        return match (true) {
            $response->status() === 401 => throw KoboAuthFailed::credentials(401),
            $response->status() === 403 => throw KoboAuthFailed::forbidden(403),
            ! $response->successful() => throw KoboUnexpectedResponse::forStatus($response->status(), $endpoint),
            default => $response,
        };
    }

    /**
     * Sends with retries on 429, 5xx and connection errors; returns any other response.
     *
     * @param  Closure(): Response  $request
     */
    private function send(string $method, string $endpoint, Closure $request): Response
    {
        $attempts = $this->config->attempts;
        for ($attempt = 1; ; $attempt++) {
            $started = hrtime(true);
            try {
                $response = $request();
            } catch (ConnectionException) {
                Log::channel('edqa')->warning('kobo.request', ['method' => $method, 'endpoint' => $endpoint, 'error' => 'connection', 'attempt' => $attempt]);
                if ($attempt >= $attempts) {
                    throw KoboUnreachable::connection($attempts);
                }
                $this->wait($attempt, null, $endpoint, 'connection');

                continue;
            }

            $status = $response->status();
            Log::channel('edqa')->info('kobo.request', [
                'method' => $method, 'endpoint' => $endpoint, 'status' => $status, 'attempt' => $attempt,
                'ms' => (int) ((hrtime(true) - $started) / 1e6),
            ]);
            if ($status !== 429 && $status < 500) {
                return $response;
            }
            if ($attempt >= $attempts) {
                throw KoboUnreachable::server($status, $attempts);
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
        Log::channel('edqa')->warning('kobo.retry', ['endpoint' => $endpoint, 'reason' => $reason, 'attempt' => $attempt, 'wait_ms' => $ms]);
        Sleep::for($ms)->milliseconds();
    }

    private function http(): PendingRequest
    {
        return Http::withOptions(['verify' => true])
            ->connectTimeout($this->config->connectTimeout)
            ->timeout($this->config->timeout)
            ->withToken((string) $this->config->token, 'Token')
            ->acceptJson()
            ->withUserAgent('kaduna-edqa');
    }

    /** A redirect's Location against the URL it came from (absolute, or a path on the same host). */
    private static function resolve(string $from, string $location): string
    {
        if ($location === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($from);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return str_starts_with($location, '/') ? $origin.$location : $origin.'/'.$location;
    }

    /** @param  array<string, string|int>  $query */
    private static function queryString(array $query): string
    {
        if ($query === []) {
            return '';
        }

        return '?'.implode('&', array_map(fn (string $key, string|int $value): string => rawurlencode($key).'='.rawurlencode((string) $value), array_keys($query), $query));
    }

    /** The endpoint without asset, submission, attachment or version IDs, for logs and messages. */
    private static function endpoint(string $path): string
    {
        return trim((string) preg_replace(
            ['#^/api/v2#', '#/assets/[^/]+#', '#/data/[^/]+#', '#/attachments/[^/]+#', '#/versions/[^/]+#'],
            ['', '/assets/{asset}', '/data/{submission}', '/attachments/{attachment}', '/versions/{version}'],
            rtrim($path, '/'),
        ), '/');
    }
}
