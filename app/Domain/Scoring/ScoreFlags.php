<?php

declare(strict_types=1);

namespace App\Domain\Scoring;

use App\Domain\Scoring\DTOs\ScoreCard;

/**
 * The two soft rules that depend only on derived scores, as pure checks: the SCORE_JUMP and
 * ALL_PERFECT rules use them at ingestion and RescoreAssessments after a rescore, so a flag means
 * the same thing whichever raised it. Full precision throughout.
 */
final class ScoreFlags
{
    public const SCORE_JUMP = 'SCORE_JUMP';

    public const ALL_PERFECT = 'ALL_PERFECT';

    public const SLOTS = 9;

    /** All nine dimension-month scores exactly 100 (an all-N/A slot has no score, so it is not perfect). */
    public static function allPerfect(ScoreCard $card): bool
    {
        $perfect = 0;
        foreach ($card->slots as $months) {
            foreach ($months as $score) {
                if ($score === 100.0) {
                    $perfect++;
                }
            }
        }

        return $perfect === self::SLOTS;
    }

    /** The overall moved more than $maxJump from the previous round's; exactly the threshold passes. */
    public static function jumped(float $overall, ?float $previous, int|float $maxJump): bool
    {
        return $previous !== null && abs($overall - $previous) > $maxJump;
    }
}
