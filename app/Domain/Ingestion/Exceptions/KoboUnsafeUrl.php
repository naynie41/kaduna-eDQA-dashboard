<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

/**
 * A URL from a Kobo payload that the portal will not follow (SECURITY.md §6, SSRF): not https, a
 * host other than the configured Kobo server, or a redirect away from it. Nothing was fetched
 * from it. The message names the problem, never the URL's query (it may carry a signature).
 */
final class KoboUnsafeUrl extends KoboException
{
    public static function scheme(): self
    {
        return new self((string) __('kobo.errors.unsafe_scheme'));
    }

    public static function host(string $host): self
    {
        return new self((string) __('kobo.errors.unsafe_host', ['host' => $host]));
    }

    public static function redirect(string $host): self
    {
        return new self((string) __('kobo.errors.unsafe_redirect', ['host' => $host]));
    }

    public function status(): string
    {
        return 'unsafe_url';
    }
}
