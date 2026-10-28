<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

/** The base URL, token or asset UID is missing, or the URL is not https: nothing was sent. */
final class KoboNotConfigured extends KoboException
{
    /** @param  list<string>  $problems  env keys to set, and "KOBO_BASE_URL (https)" for an http URL */
    public function __construct(public readonly array $problems)
    {
        parent::__construct((string) __('kobo.errors.not_configured', ['problems' => implode(', ', $problems)]));
    }

    public function status(): string
    {
        return 'not_configured';
    }
}
