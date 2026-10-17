<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

use RuntimeException;

/**
 * Anything that stopped a request to the submission source. The message is for an administrator:
 * it never holds a password, a session token or a payload (SECURITY.md §6). status() is the
 * machine-readable kind ("not_configured", …) for edqa:odk:check --json and the pull run.
 */
abstract class OdkException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    abstract public function status(): string;
}
