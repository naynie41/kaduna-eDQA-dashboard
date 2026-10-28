<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

/**
 * KoboToolbox refused the token (401), or the account has no access to the asset (403). Never
 * retried: the source stops until the token is fixed (SECURITY.md §6).
 */
final class KoboAuthFailed extends KoboException
{
    public static function credentials(int $status): self
    {
        return new self((string) __('kobo.errors.auth_credentials', ['status' => $status]), $status);
    }

    public static function forbidden(int $status): self
    {
        return new self((string) __('kobo.errors.auth_forbidden', ['status' => $status]), $status);
    }

    public function status(): string
    {
        return 'auth_failed';
    }
}
