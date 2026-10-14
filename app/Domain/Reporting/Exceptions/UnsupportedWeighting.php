<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exceptions;

use RuntimeException;

/**
 * edqa.aggregation.weighting names a method no one has defined (Q-27). Refused rather than
 * guessed: it would change every number on every page.
 */
final class UnsupportedWeighting extends RuntimeException
{
    public static function named(string $weighting): self
    {
        $message = __('reporting.errors.unsupported_weighting', ['weighting' => $weighting]);

        return new self(is_string($message) ? $message : "Unsupported weighting {$weighting}.");
    }
}
