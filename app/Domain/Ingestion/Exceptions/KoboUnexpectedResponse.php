<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

/** An answer the client cannot use: another 4xx (no retry), or a body that is not what the API documents. */
final class KoboUnexpectedResponse extends KoboException
{
    /** @param  string  $what  the endpoint, without IDs ("assets/{asset}/data") */
    public static function forStatus(int $status, string $what): self
    {
        return new self((string) __('kobo.errors.unexpected_status', ['status' => $status, 'what' => $what]), $status);
    }

    public static function malformed(int $status, string $what): self
    {
        return new self((string) __('kobo.errors.unexpected_body', ['status' => $status, 'what' => $what]), $status);
    }

    public static function attachmentMissing(string $name): self
    {
        return new self((string) __('kobo.errors.attachment_missing', ['name' => $name]), 404);
    }

    public function status(): string
    {
        return 'unexpected_response';
    }
}
