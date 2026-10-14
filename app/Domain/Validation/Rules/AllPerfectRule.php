<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Scoring\ScoreFlags;
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

    public function __construct(
        private readonly SubmissionScorer $scorer,
    ) {}

    public function code(): string
    {
        return ScoreFlags::ALL_PERFECT;
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

        return ScoreFlags::allPerfect($card) ? [$this->fail('detail', ['count' => ScoreFlags::SLOTS], 'items')] : [];
    }
}
