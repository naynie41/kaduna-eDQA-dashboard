<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\DTOs\RoundSnapshot;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\ValidationContext;

/**
 * ROUND_WINDOW: the visit date (start, else submission time) must fall inside exactly one round's
 * window, that round must be open, and when the form names its round (year and quarter both
 * given) it must be that round (D-28). The nearest round is never guessed: no round, two
 * overlapping rounds, a closed round and a disagreeing form each quarantine with their own detail.
 */
final class RoundWindowRule implements ValidationRule
{
    use BuildsFailures;

    public function code(): string
    {
        return 'ROUND_WINDOW';
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
        $date = $submission->visitDate()->toDateString();
        $field = $submission->startedAt !== null ? 'started_at' : 'submitted_at';
        $rounds = $context->roundsContaining($submission->visitDate());

        if ($rounds === []) {
            return [$this->fail('detail', ['date' => $date], $field)];
        }
        if (count($rounds) > 1) {
            $labels = array_map(fn (RoundSnapshot $r): string => $this->roundLabel($r->year, $r->quarter), $rounds);

            return [$this->fail('detail_ambiguous', ['date' => $date, 'rounds' => implode(', ', $labels)], $field)];
        }

        $round = $rounds[0];
        $label = $this->roundLabel($round->year, $round->quarter);
        $failures = [];

        if (! $round->isOpen()) {
            $failures[] = $this->fail('detail_closed', ['date' => $date, 'round' => $label], $field);
        }

        $year = $submission->roundYear;
        $quarter = $submission->roundQuarter;
        if ($year !== null && $quarter !== null && ($year !== $round->year || $quarter !== $round->quarter)) {
            $failures[] = $this->fail('detail_declared', [
                'declared' => $this->roundLabel($year, $quarter),
                'date' => $date,
                'round' => $label,
            ], 'round');
        }

        return $failures;
    }
}
