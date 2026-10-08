<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules\Concerns;

use App\Domain\Validation\DTOs\RuleFailure;

/**
 * Builds a rule's failure from its wording in lang/en/validation_rules.php, so every detail a
 * user reads comes from the language file (CLAUDE.md hard rule 10).
 */
trait BuildsFailures
{
    /** @param  array<string, string|int|float>  $replace */
    protected function fail(string $key, array $replace = [], ?string $field = null): RuleFailure
    {
        return new RuleFailure($this->code(), $this->severity(), $this->text($key, $replace), $field);
    }

    /** For a detail already composed from several text() parts. */
    protected function failWithDetail(string $detail, ?string $field = null): RuleFailure
    {
        return new RuleFailure($this->code(), $this->severity(), $detail, $field);
    }

    /** "Q2 2026" */
    protected function roundLabel(int $year, int $quarter): string
    {
        $label = __('validation_rules.round_label', ['quarter' => (string) $quarter, 'year' => (string) $year]);

        return is_string($label) ? $label : "Q{$quarter} {$year}";
    }

    /** A number as typed: 347.66, -4, 100 (no padding, no rounding). */
    protected function number(float $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, string|int|float>  $replace */
    protected function text(string $key, array $replace = []): string
    {
        $text = __("validation_rules.{$this->code()}.{$key}", array_map(strval(...), $replace));

        return is_string($text) ? $text : "validation_rules.{$this->code()}.{$key}";
    }
}
