<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Exceptions;

use App\Domain\Scoring\Enums\Dimension;
use RuntimeException;

/**
 * A whole dimension has no applicable item, so it cannot be scored. ITEMS_INCOMPLETE (hard)
 * should have quarantined the submission first; this is the calculator's safeguard.
 */
final class IncompleteAssessmentException extends RuntimeException
{
    public static function forDimension(Dimension $dimension): self
    {
        return new self("Dimension {$dimension->value} has no applicable item in any month slot.");
    }
}
