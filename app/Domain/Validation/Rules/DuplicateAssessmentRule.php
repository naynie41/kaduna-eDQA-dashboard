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
 * The detail names both sources (ARCHITECTURE.md §5.8): the one the accepted assessment came from
 * and this submission's, whichever tool each was entered with.
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
            'existing_source' => self::sourceName($context->acceptedSource($existing)),
            'source' => self::sourceName($submission->source),
        ], 'facility_code')];
    }

    /** A source as administrators know it ("ODK Central"); its key when config has no name. */
    private static function sourceName(?string $key): string
    {
        if ($key === null || $key === '') {
            return (string) __('validation_rules.DUPLICATE_ASSESSMENT.unknown_source');
        }
        $name = config("edqa.sources.{$key}.name");

        return is_string($name) && $name !== '' ? $name : $key;
    }
}
