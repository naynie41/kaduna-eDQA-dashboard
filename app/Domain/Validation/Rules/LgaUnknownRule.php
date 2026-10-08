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
 * LGA_UNKNOWN: the LGA must resolve, by code or by name (case, spacing and punctuation ignored),
 * to one of the 23 seeded LGAs. Blank, whitespace and "null" fail: the live report's null LGA
 * became a 24th LGA.
 */
final class LgaUnknownRule implements ValidationRule
{
    use BuildsFailures;

    public function code(): string
    {
        return 'LGA_UNKNOWN';
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
        $ref = Normalise::nullIfBlank($submission->lgaRef);

        if ($ref === null) {
            return [$this->fail('detail', field: 'lga')];
        }
        if ($context->lga($ref) === null) {
            return [$this->fail('detail_unknown', ['value' => $ref], 'lga')];
        }

        return [];
    }
}
