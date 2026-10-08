<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\ValidationContext;
use Illuminate\Container\Attributes\Config;

/**
 * VISIT_TOO_SHORT (soft): the visit lasted less than visit_min_minutes; exactly the minimum
 * passes. Measured to the second, shown in whole minutes rounded down. Skipped when either time
 * did not parse (COLUMN_DRIFT) or the visit ends before it starts (END_BEFORE_START).
 */
final class VisitTooShortRule implements ValidationRule
{
    use BuildsFailures;

    public function __construct(
        #[Config('edqa.validation.soft_rules.visit_min_minutes')] private readonly int $minimumMinutes,
    ) {}

    public function code(): string
    {
        return 'VISIT_TOO_SHORT';
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
        if ($submission->startedAt === null || $submission->endedAt === null) {
            return [];
        }

        $seconds = $submission->endedAt->getTimestamp() - $submission->startedAt->getTimestamp();
        if ($seconds < 0 || $seconds >= $this->minimumMinutes * 60) {
            return [];
        }

        return [$this->fail('detail', [
            'minutes' => intdiv($seconds, 60),
            'minimum' => $this->minimumMinutes,
        ], 'ended_at')];
    }
}
