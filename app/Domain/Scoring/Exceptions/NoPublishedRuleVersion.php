<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Exceptions;

use RuntimeException;

/**
 * A submission passed validation but there is no published scoring rule version to score it
 * with. A setup error (the seeder publishes version 1), so processing stops rather than accept
 * an unscored assessment.
 */
final class NoPublishedRuleVersion extends RuntimeException
{
    public static function forSubmission(string $instanceId): self
    {
        return new self("No published scoring rule version: cannot score submission {$instanceId}.");
    }

    public static function forOpenRound(): self
    {
        return new self('No published scoring rule version: an open round has nothing to be scored under.');
    }
}
