<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

/** Credentials or IDs are missing, or the URL is not https: nothing was sent. */
final class OdkNotConfigured extends OdkException
{
    /** @param  list<string>  $problems  env keys to set, and "ODK_CENTRAL_URL (https)" for an http URL */
    public function __construct(public readonly array $problems)
    {
        parent::__construct((string) __('odk.errors.not_configured', ['problems' => implode(', ', $problems)]));
    }

    public function status(): string
    {
        return 'not_configured';
    }
}
