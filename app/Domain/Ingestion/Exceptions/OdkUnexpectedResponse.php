<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

/** An answer the client cannot use: another 4xx (no retry), or a body that is not what the API documents. */
final class OdkUnexpectedResponse extends OdkException
{
    /** @param  string  $what  the endpoint, without query values ("forms/{form}.svc/Submissions") */
    public static function forStatus(int $status, string $what): self
    {
        return new self((string) __('odk.errors.unexpected_status', ['status' => $status, 'what' => $what]), $status);
    }

    public static function malformed(int $status, string $what): self
    {
        return new self((string) __('odk.errors.unexpected_body', ['status' => $status, 'what' => $what]), $status);
    }

    public function status(): string
    {
        return 'unexpected_response';
    }
}
