<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

use RuntimeException;

/**
 * An administrator asked a source to pull or reconcile, and it cannot (ARCHITECTURE.md §8: a
 * domain error, 409 with { code, message }): its settings are missing, it is switched off, or
 * (pull-all) no source is on. code() is one of lang sources.api's keys.
 */
final class SourceUnavailable extends RuntimeException
{
    private function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $name): self
    {
        return new self('source_not_configured', (string) __('sources.api.source_not_configured', ['source' => $name]));
    }

    public static function disabled(string $name): self
    {
        return new self('source_disabled', (string) __('sources.api.source_disabled', ['source' => $name]));
    }

    public static function noneEnabled(): self
    {
        return new self('no_source_enabled', (string) __('sources.api.no_source_enabled'));
    }

    public function code(): string
    {
        return $this->reason;
    }
}
