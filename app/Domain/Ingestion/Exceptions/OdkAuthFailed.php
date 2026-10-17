<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

/** ODK Central refused the account: wrong email or password (401), or no access to the project or form (403). */
final class OdkAuthFailed extends OdkException
{
    public static function credentials(int $status): self
    {
        return new self((string) __('odk.errors.auth_credentials', ['status' => $status]), $status);
    }

    public static function forbidden(int $status): self
    {
        return new self((string) __('odk.errors.auth_forbidden', ['status' => $status]), $status);
    }

    public function status(): string
    {
        return 'auth_failed';
    }
}
