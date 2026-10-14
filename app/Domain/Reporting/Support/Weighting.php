<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use App\Domain\Reporting\Exceptions\UnsupportedWeighting;

/**
 * edqa.aggregation.weighting (ARCHITECTURE.md §7, D-04). 'unweighted': every facility counts
 * once in a mean, up to LGA, owner and state; that is what the reference figures use. Weighting
 * group means by facility count gives exactly the same numbers, so 'facility_count' must mean
 * something else; until Q-27 settles it, it is refused rather than guessed.
 */
final class Weighting
{
    public const UNWEIGHTED = 'unweighted';

    public static function assertSupported(): void
    {
        $weighting = config('edqa.aggregation.weighting', self::UNWEIGHTED);

        if ($weighting !== self::UNWEIGHTED) {
            throw UnsupportedWeighting::named(is_string($weighting) ? $weighting : 'unknown');
        }
    }
}
