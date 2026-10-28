<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

use RuntimeException;

/**
 * Anything that stopped a request to a field-collection source (ODK Central, KoboToolbox). The
 * message is for an administrator: it never holds a password, a token or a payload (SECURITY.md
 * §6). status() is the machine-readable kind ("not_configured", "auth_failed", …) for
 * edqa:source:check --json and the pull run; it means the same on every source.
 */
abstract class SourceException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    abstract public function status(): string;
}
