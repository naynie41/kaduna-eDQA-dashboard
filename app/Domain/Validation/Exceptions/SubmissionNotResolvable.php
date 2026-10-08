<?php

declare(strict_types=1);

namespace App\Domain\Validation\Exceptions;

use RuntimeException;

/**
 * Thrown when a submission is registered as accepted but its facility or round cannot be
 * resolved: it could not have passed the hard rules, so this is a pipeline bug.
 */
final class SubmissionNotResolvable extends RuntimeException
{
    public static function forAcceptance(string $instanceId): self
    {
        return new self("Submission {$instanceId} was registered as accepted, but its facility or round does not resolve.");
    }
}
