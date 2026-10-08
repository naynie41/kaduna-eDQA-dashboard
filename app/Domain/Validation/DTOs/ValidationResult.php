<?php

declare(strict_types=1);

namespace App\Domain\Validation\DTOs;

use App\Domain\Validation\Enums\Severity;
use InvalidArgumentException;

/**
 * Every failure for one submission (the pipeline is not fail-fast, ARCHITECTURE.md §6).
 * Any hard failure quarantines; soft failures only flag an accepted assessment.
 */
final readonly class ValidationResult
{
    /**
     * @param  list<RuleFailure>  $hardFailures
     * @param  list<RuleFailure>  $softFlags
     */
    public function __construct(
        public array $hardFailures,
        public array $softFlags,
    ) {
        foreach ($hardFailures as $failure) {
            if ($failure->severity !== Severity::Hard) {
                throw new InvalidArgumentException("{$failure->code} is soft but was filed as a hard failure.");
            }
        }
        foreach ($softFlags as $failure) {
            if ($failure->severity !== Severity::Soft) {
                throw new InvalidArgumentException("{$failure->code} is hard but was filed as a soft flag.");
            }
        }
    }

    /** @param  list<RuleFailure>  $failures  in rule order */
    public static function fromFailures(array $failures): self
    {
        return new self(
            array_values(array_filter($failures, fn (RuleFailure $f): bool => $f->severity === Severity::Hard)),
            array_values(array_filter($failures, fn (RuleFailure $f): bool => $f->severity === Severity::Soft)),
        );
    }

    /** No hard failure: the submission becomes an assessment. */
    public function passed(): bool
    {
        return $this->hardFailures === [];
    }

    /** At least one soft rule hit: the assessment carries flags. */
    public function isFlagged(): bool
    {
        return $this->softFlags !== [];
    }

    /** @return list<RuleFailure> hard failures first, then soft flags */
    public function failures(): array
    {
        return [...$this->hardFailures, ...$this->softFlags];
    }
}
