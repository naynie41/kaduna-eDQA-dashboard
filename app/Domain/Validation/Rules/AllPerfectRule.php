<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\Support\SubmissionScorer;
use App\Domain\Validation\ValidationContext;

/**
 * ALL_PERFECT (soft): all nine dimension-month scores exactly 100, which can mean the form was
 * filled in without a visit. An all-N/A slot has no score, so eight perfect slots and one N/A
 * slot pass. Silent when the submission cannot be scored.
 */
final class AllPerfectRule implements ValidationRule
{
    use BuildsFailures;

    private const SLOTS = 9;

    public function __construct(
        private readonly SubmissionScorer $scorer,
    ) {}

    public function code(): string
    {
        return 'ALL_PERFECT';
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
        $card = $this->scorer->scoreCard($submission, $context);
        if ($card === null) {
            return [];
        }

        $perfect = 0;
        foreach ($card->slots as $months) {
            foreach ($months as $score) {
                if ($score === 100.0) {
                    $perfect++;
                }
            }
        }

        return $perfect === self::SLOTS ? [$this->fail('detail', ['count' => self::SLOTS], 'items')] : [];
    }
}
