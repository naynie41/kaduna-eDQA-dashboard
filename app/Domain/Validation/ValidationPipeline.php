<?php

declare(strict_types=1);

namespace App\Domain\Validation;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\DTOs\RuleFailure;
use App\Domain\Validation\DTOs\ValidationResult;
use App\Domain\Validation\Enums\Severity;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Runs every rule in config order (edqa.validation.rules) and collects every failure: not
 * fail-fast, so the Data issues page shows all reasons at once (ARCHITECTURE.md §6).
 *
 * - When UNKNOWN_FORM_VERSION fails, rules that read mapped fields (dependsOnParse) are skipped:
 *   read through the wrong map, their answers would mean nothing.
 * - A rule that throws, or cannot be built, becomes a RULE_ERROR hard failure and is logged. A
 *   broken rule quarantines; it never lets a submission through.
 */
final class ValidationPipeline
{
    public const FORM_VERSION_GATE = 'UNKNOWN_FORM_VERSION';

    public const RULE_ERROR = 'RULE_ERROR';

    /** @param  array<string, string>  $rules  code => rule class, in run order */
    public function __construct(
        private readonly Container $container,
        #[Config('edqa.validation.rules')] private readonly array $rules,
    ) {}

    public function validate(ParsedSubmission $submission, ValidationContext $context): ValidationResult
    {
        $failures = [];
        $parseUsable = true;

        foreach ($this->rules as $code => $class) {
            try {
                $rule = $this->container->make($class);
                if (! $rule instanceof ValidationRule) {
                    throw new LogicException("{$class} does not implement ValidationRule.");
                }
                if (! $parseUsable && $rule->dependsOnParse()) {
                    continue;
                }

                $found = $rule->check($submission, $context);
            } catch (Throwable $e) {
                $found = [$this->ruleError($code, $submission, $e)];
            }

            if ($code === self::FORM_VERSION_GATE && $found !== []) {
                $parseUsable = false;
            }
            $failures = [...$failures, ...$found];
        }

        return ValidationResult::fromFailures($failures);
    }

    private function ruleError(string $code, ParsedSubmission $submission, Throwable $e): RuleFailure
    {
        // Structured context only: never the payload (CONVENTION.md §2.9).
        Log::channel('edqa')->error('validation.rule_error', [
            'rule' => $code,
            'instance_id' => $submission->instanceId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $detail = __('validation_rules.RULE_ERROR.detail', ['rule' => $code, 'message' => $e->getMessage()]);

        return new RuleFailure(self::RULE_ERROR, Severity::Hard, is_string($detail) ? $detail : self::RULE_ERROR);
    }
}
