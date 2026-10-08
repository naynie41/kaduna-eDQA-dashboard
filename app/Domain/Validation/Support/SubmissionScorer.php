<?php

declare(strict_types=1);

namespace App\Domain\Validation\Support;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Scoring\DTOs\ScoreCard;
use App\Domain\Scoring\Exceptions\IncompleteAssessmentException;
use App\Domain\Scoring\ScoreCalculator;
use App\Domain\Validation\ValidationContext;

/**
 * The derived scores the rules check (SCORE_RANGE, SCORE_JUMP, ALL_PERFECT), computed with
 * ScoreCalculator under the batch's published rule version. Null when the submission cannot be
 * scored: no published version, or items that ITEMS_INCOMPLETE fails. Never throws.
 */
final readonly class SubmissionScorer
{
    public function __construct(
        private ScoreCalculator $calculator,
    ) {}

    public function scoreCard(ParsedSubmission $submission, ValidationContext $context): ?ScoreCard
    {
        $rules = $context->ruleConfig();
        if ($rules === null || ! ItemCoverage::isComplete($submission->items)) {
            return null;
        }

        try {
            return $this->calculator->calculate($submission->items, $rules);
        } catch (IncompleteAssessmentException) {
            return null; // Unreachable after isComplete(); kept so no exception leaks.
        }
    }
}
