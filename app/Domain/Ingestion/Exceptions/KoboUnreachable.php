<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

/** No usable answer after every retry: the connection failed (TLS included), or the server kept answering 429 or 5xx. */
final class KoboUnreachable extends KoboException
{
    public static function connection(int $attempts): self
    {
        return new self((string) __('kobo.errors.unreachable_connection', ['attempts' => $attempts]));
    }

    public static function server(int $status, int $attempts): self
    {
        return new self((string) __('kobo.errors.unreachable_server', ['status' => $status, 'attempts' => $attempts]), $status);
    }

    public function status(): string
    {
        return 'unreachable';
    }
}
