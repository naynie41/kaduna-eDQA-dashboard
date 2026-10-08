<?php

declare(strict_types=1);

namespace App\Domain\Validation\Contracts;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\DTOs\RuleFailure;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\ValidationContext;

/**
 * One validation rule (ARCHITECTURE.md §6). Registered in config/edqa.php under
 * validation.rules; adding a rule means adding a class and a config line.
 *
 * Rules read only the submission and the batch context: never the database, never ODK.
 */
interface ValidationRule
{
    /** e.g. 'SCORE_RANGE'; also the key of its text in lang/en/validation_rules.php. */
    public function code(): string;

    public function severity(): Severity;

    /**
     * True when the rule reads mapped fields, so its answer means nothing if the form version
     * has no field map. The pipeline skips these rules after UNKNOWN_FORM_VERSION fails.
     */
    public function dependsOnParse(): bool;

    /** @return list<RuleFailure> empty when passing */
    public function check(ParsedSubmission $submission, ValidationContext $context): array;
}
