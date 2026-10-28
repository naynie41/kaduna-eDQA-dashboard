<?php

declare(strict_types=1);

namespace App\Domain\Validation;

use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\Container;

/**
 * Every rule code the Data issues page names (ARCHITECTURE.md §6): the 14 rules of
 * edqa.validation.rules in run order, each with its severity, and RULE_ERROR (hard), the
 * pipeline's own failure when a rule cannot run. One list, so the summary, the filters and the
 * soft-flag override agree.
 */
final class RuleCatalogue
{
    /** @param  array<string, string>  $rules  code => rule class, in run order */
    public function __construct(
        private readonly Container $container,
        #[Config('edqa.validation.rules')] private readonly array $rules,
    ) {}

    /** @return list<array{code: string, severity: Severity}> */
    public function all(): array
    {
        $codes = [];
        foreach ($this->rules as $code => $class) {
            $rule = $this->container->make($class);
            if ($rule instanceof ValidationRule) {
                $codes[] = ['code' => $code, 'severity' => $rule->severity()];
            }
        }
        $codes[] = ['code' => ValidationPipeline::RULE_ERROR, 'severity' => Severity::Hard];

        return $codes;
    }

    /** @return list<string> */
    public function codes(Severity $severity): array
    {
        return array_values(array_map(fn (array $rule): string => $rule['code'], array_filter($this->all(), fn (array $rule): bool => $rule['severity'] === $severity)));
    }
}
