<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\ValidationContext;

/**
 * END_BEFORE_START: a visit cannot end before it starts. Compares instants, so offsets don't
 * matter; ending the moment it starts passes. Skipped when either time did not parse
 * (COLUMN_DRIFT reports that). The detail shows local times.
 */
final class EndBeforeStartRule implements ValidationRule
{
    use BuildsFailures;

    public function code(): string
    {
        return 'END_BEFORE_START';
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
        $start = $submission->startedAt;
        $end = $submission->endedAt;

        if ($start === null || $end === null || ! $end->lessThan($start)) {
            return [];
        }

        $timezone = (string) config('app.timezone');

        return [$this->fail('detail', [
            'end' => $end->setTimezone($timezone)->format('Y-m-d H:i'),
            'start' => $start->setTimezone($timezone)->format('Y-m-d H:i'),
        ], 'ended_at')];
    }
}
