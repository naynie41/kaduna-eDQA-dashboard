<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\ValidationContext;
use App\Support\Text\Normalise;

/**
 * FACILITY_UNKNOWN: the facility ref must be the exact code (surrounding spaces ignored) of an
 * active master-list facility. The detail says why: blank, a bare number (live report:
 * "2019.00"), deactivated, or simply not on the list.
 */
final class FacilityUnknownRule implements ValidationRule
{
    use BuildsFailures;

    public function code(): string
    {
        return 'FACILITY_UNKNOWN';
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
        $ref = Normalise::nullIfBlank($submission->facilityRef);
        if ($ref === null) {
            return [$this->fail('detail_blank', field: 'facility_code')];
        }

        $facility = $context->facility($ref);

        return match (true) {
            $facility?->isActive === true => [],
            $facility !== null => [$this->fail('detail_inactive', ['code' => $facility->code], 'facility_code')],
            Normalise::isNumeric($ref) => [$this->fail('detail_numeric', ['code' => $ref], 'facility_code')],
            default => [$this->fail('detail', ['code' => $ref], 'facility_code')],
        };
    }
}
