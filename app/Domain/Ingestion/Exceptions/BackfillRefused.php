<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

use RuntimeException;

/** A backfill that must not start (ARCHITECTURE.md §5.6); the message is from lang/en/backfill.php. */
final class BackfillRefused extends RuntimeException
{
    private function __construct(public readonly string $reason)
    {
        parent::__construct((string) __("backfill.refused.{$reason}"));
    }

    /** Rounds come first: a visit with no round would quarantine under ROUND_WINDOW, never be guessed. */
    public static function noHistoricalRounds(): self
    {
        return new self('no_historical_rounds');
    }

    public static function alreadyRunning(): self
    {
        return new self('already_running');
    }
}
