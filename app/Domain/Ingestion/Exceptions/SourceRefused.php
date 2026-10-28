<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

use RuntimeException;

/**
 * A source key or component that cannot be used (ARCHITECTURE.md §5.0), answered as 409
 * { code, message } with the message from lang/en/sources.php.
 */
final class SourceRefused extends RuntimeException
{
    /** @param  array<string, string>  $replace */
    private function __construct(
        public readonly string $errorCode,
        array $replace = [],
    ) {
        $message = __("sources.errors.{$errorCode}", $replace);

        parent::__construct(is_string($message) ? $message : $errorCode);
    }

    /** Not in edqa.sources. */
    public static function unknown(string $key): self
    {
        return new self('unknown_source', ['key' => $key]);
    }

    /** In config, but its submission_sources row has not been created yet. */
    public static function notRegistered(string $key): self
    {
        return new self('not_registered', ['key' => $key]);
    }

    /** A source type whose adapter is not built yet (KoboToolbox until Phase 2C step 4). */
    public static function noAdapter(string $key, string $type): self
    {
        return new self('no_adapter', ['key' => $key, 'type' => $type]);
    }
}
