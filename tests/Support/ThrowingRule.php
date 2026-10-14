<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\ValidationContext;
use RuntimeException;

/** A broken rule, for proving the pipeline quarantines rather than lets a submission through. */
final class ThrowingRule implements ValidationRule
{
    public function code(): string
    {
        return 'BROKEN_RULE';
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
        throw new RuntimeException('division by zero in test rule');
    }
}
