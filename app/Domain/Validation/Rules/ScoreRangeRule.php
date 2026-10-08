<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\DTOs\RuleFailure;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\Support\SubmissionScorer;
use App\Domain\Validation\ValidationContext;

/**
 * SCORE_RANGE: every value must be within 0–100 (live report: 347.66, 392.42). Defence in
 * depth; the assessment_scores CHECK is the backstop.
 *
 * - Derived: ScoreCalculator under the current published rule version, checked slot,
 *   dimension and overall. Skipped when the items are incomplete (ITEMS_INCOMPLETE reports
 *   that) or no version is published, so nothing throws out of the rule.
 * - Typed: every rawNumericScores value from a legacy numeric-entry form. These are checked,
 *   never used as scores (CLAUDE.md hard rule 2).
 */
final class ScoreRangeRule implements ValidationRule
{
    use BuildsFailures;

    public function __construct(
        private readonly SubmissionScorer $scorer,
    ) {}

    public function code(): string
    {
        return 'SCORE_RANGE';
    }

    public function severity(): Severity
    {
        return Severity::Hard;
    }

    public function dependsOnParse(): bool
    {
        return true;
    }

    public function check(ParsedSubmission $submission, ValidationContext $context): array
    {
        $failures = [];

        foreach ($this->derivedValues($submission, $context) as $name => $value) {
            $failures[] = $this->outOfRange($name, $value);
        }
        foreach ($submission->rawNumericScores ?? [] as $name => $value) {
            $failures[] = $this->outOfRange($name, $value);
        }

        return array_values(array_filter($failures));
    }

    /** @return array<string, float> "availability_m1", "availability", ..., "overall" */
    private function derivedValues(ParsedSubmission $submission, ValidationContext $context): array
    {
        $card = $this->scorer->scoreCard($submission, $context);
        if ($card === null) {
            return [];
        }

        $values = [];
        foreach ($card->slots as $dimension => $months) {
            foreach ($months as $month => $score) {
                if ($score !== null) {
                    $values["{$dimension}_m{$month}"] = $score;
                }
            }
        }

        return [...$values, ...$card->dimensions, 'overall' => $card->overall];
    }

    private function outOfRange(string $name, float $value): ?RuleFailure
    {
        return match (true) {
            $value > 100 => $this->fail('detail', ['slot' => $name, 'score' => $this->number($value)], $name),
            $value < 0 => $this->fail('detail_below', ['slot' => $name, 'score' => $this->number($value)], $name),
            default => null,
        };
    }
}
