<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\ValidationContext;

/**
 * DUPLICATE_ASSESSMENT: one accepted assessment per (round, facility), counting the database
 * and submissions accepted earlier in this batch. Not a duplicate: an ODK edit whose
 * deprecatedID is the submission behind the existing assessment, or a reprocess of that same
 * instance (ValidationContext::duplicateOf).
 *
 * Silent when the facility or round did not resolve: FACILITY_UNKNOWN and ROUND_WINDOW report it.
 */
final class DuplicateAssessmentRule implements ValidationRule
{
    use BuildsFailures;

    public function code(): string
    {
        return 'DUPLICATE_ASSESSMENT';
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
        $facility = $context->facility($submission->facilityRef);
        $round = $context->roundFor($submission);
        if ($facility === null || $round === null) {
            return [];
        }

        $existing = $context->duplicateOf($submission, $round->id, $facility->id);
        if ($existing === null) {
            return [];
        }

        return [$this->fail('detail', [
            'round' => $this->roundLabel($round->year, $round->quarter),
            'code' => $facility->code,
            'instance' => $existing,
        ], 'facility_code')];
    }
}
