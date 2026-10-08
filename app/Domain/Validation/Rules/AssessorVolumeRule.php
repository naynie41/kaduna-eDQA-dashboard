<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\ValidationContext;
use App\Support\Text\Normalise;
use Illuminate\Container\Attributes\Config;

/**
 * ASSESSOR_VOLUME (soft): the assessor (name ignoring case and spacing, plus device ID when
 * present; D-29) visited more than assessor_max_per_day distinct facilities on the visit's local
 * day, counting accepted assessments, this batch's accepted submissions and this visit. Exactly
 * the maximum passes.
 */
final class AssessorVolumeRule implements ValidationRule
{
    use BuildsFailures;

    public function __construct(
        #[Config('edqa.validation.soft_rules.assessor_max_per_day')] private readonly int $maximum,
    ) {}

    public function code(): string
    {
        return 'ASSESSOR_VOLUME';
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
        $day = $submission->visitDate();
        $facility = $context->facility($submission->facilityRef);

        // An unresolved facility is still a visit; it just cannot be matched to an earlier one.
        $count = $facility !== null
            ? $context->assessorVisitCount($submission->assessorName, $submission->deviceId, $day, $facility->id)
            : $context->assessorVisitCount($submission->assessorName, $submission->deviceId, $day) + 1;

        if ($count <= $this->maximum) {
            return [];
        }

        return [$this->fail('detail', [
            'assessor' => Normalise::collapse($submission->assessorName),
            'count' => $count,
            'date' => $day->toDateString(),
        ], 'assessor')];
    }
}
