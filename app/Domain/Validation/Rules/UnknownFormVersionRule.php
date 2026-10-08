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
 * UNKNOWN_FORM_VERSION: the submission's form version must have a field map. Runs first; when
 * it fails, the rules that read mapped fields are skipped (ARCHITECTURE.md §5.5, §6).
 *
 * Both the parser's answer and the config must agree the version is known: a mismatch means one
 * of them is wrong, and an old form read with the wrong map is the worst outcome.
 */
final class UnknownFormVersionRule implements ValidationRule
{
    use BuildsFailures;

    public function code(): string
    {
        return 'UNKNOWN_FORM_VERSION';
    }

    public function severity(): Severity
    {
        return Severity::Hard;
    }

    public function dependsOnParse(): bool
    {
        return false;
    }

    public function check(ParsedSubmission $submission, ValidationContext $context): array
    {
        if ($submission->formVersionKnown && $context->isKnownFormVersion($submission->formVersion)) {
            return [];
        }

        $version = Normalise::nullIfBlank($submission->formVersion) ?? $this->text('blank');

        return [$this->fail('detail', ['version' => $version], 'form_version')];
    }
}
