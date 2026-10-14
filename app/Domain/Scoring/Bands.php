<?php

declare(strict_types=1);

namespace App\Domain\Scoring;

use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Enums\Band;

/**
 * The one place a score becomes a band (D-11; ARCHITECTURE.md §7 Bands), using a rule version's
 * thresholds as lower bounds: Strong ≥ strong, Acceptable ≥ acceptable, Review ≥ review, else
 * Needs action.
 *
 * Classifies the value as the table shows it: rounded to 1 dp in the presentation mode (Q-26),
 * so a score displayed as "90.0" is never labelled Acceptable.
 */
final class Bands
{
    public const DISPLAY_DP = 1;

    public static function classify(float $value, RuleConfig $rules): Band
    {
        $shown = Presentation::round($value, self::DISPLAY_DP);

        return match (true) {
            $shown >= $rules->bands['strong'] => Band::Strong,
            $shown >= $rules->bands['acceptable'] => Band::Acceptable,
            $shown >= $rules->bands['review'] => Band::Review,
            default => Band::NeedsAction,
        };
    }
}
