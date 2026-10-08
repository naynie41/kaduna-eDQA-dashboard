<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\ValidationContext;

/**
 * FACILITY_LGA_MISMATCH: the facility's LGA on the master list must be the LGA submitted (a
 * mismatch is usually a wrong pick in the form's cascading select).
 *
 * Runs only when both resolved: an unknown LGA or facility is LGA_UNKNOWN's or
 * FACILITY_UNKNOWN's to report. A deactivated facility is still on the master list, so it is
 * compared too.
 */
final class FacilityLgaMismatchRule implements ValidationRule
{
    use BuildsFailures;

    public function code(): string
    {
        return 'FACILITY_LGA_MISMATCH';
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
        $submitted = $context->lga($submission->lgaRef);
        $facility = $context->facility($submission->facilityRef);

        if ($submitted === null || $facility === null || $facility->lgaId === $submitted->id) {
            return [];
        }

        $expected = $context->lgaById($facility->lgaId);

        return [$this->fail('detail', [
            'code' => $facility->code,
            'expected' => $expected->name ?? (string) $facility->lgaId,
            'submitted' => $submitted->name,
        ], 'lga')];
    }
}
