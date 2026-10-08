<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\Support\SubmissionScorer;
use App\Domain\Validation\ValidationContext;
use Illuminate\Container\Attributes\Config;

/**
 * SCORE_JUMP (soft): the derived overall moved more than score_jump_points from the facility's
 * overall in its most recent earlier round. Exactly the threshold passes. Silent when there is
 * no earlier round, or the submission cannot be scored or placed (other rules report that).
 */
final class ScoreJumpRule implements ValidationRule
{
    use BuildsFailures;

    public function __construct(
        private readonly SubmissionScorer $scorer,
        #[Config('edqa.validation.soft_rules.score_jump_points')] private readonly int|float $maxJump,
    ) {}

    public function code(): string
    {
        return 'SCORE_JUMP';
    }

    public function severity(): Severity
    {
        return Severity::Soft;
    }

    public function dependsOnParse(): bool
    {
        return true;
    }

    public function check(ParsedSubmission $submission, ValidationContext $context): array
    {
        $facility = $context->facility($submission->facilityRef);
        $round = $context->roundFor($submission);
        $card = $this->scorer->scoreCard($submission, $context);
        if ($facility === null || $round === null || $card === null) {
            return [];
        }

        $previous = $context->priorOverallScore($facility->id, $round);
        if ($previous === null) {
            return [];
        }

        $change = $card->overall - $previous;
        if (abs($change) <= $this->maxJump) {
            return [];
        }

        // Presentation only: the comparison above used full precision.
        return [$this->fail('detail', [
            'score' => number_format($card->overall, 1),
            'previous' => number_format($previous, 1),
            'change' => ($change > 0 ? '+' : '−').number_format(abs($change), 1),
        ], 'overall')];
    }
}
