<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\Support\ItemCoverage;
use App\Domain\Validation\ValidationContext;

/**
 * ITEMS_INCOMPLETE: all nine dimension × month slots need at least one item response, and each
 * dimension needs at least one applicable item (a missing dimension cannot be averaged).
 *
 * A legacy form with typed scores and no items lands here too (Q-16): scores are only ever
 * derived from items (CLAUDE.md hard rule 2), so there is no path that accepts them.
 */
final class ItemsIncompleteRule implements ValidationRule
{
    use BuildsFailures;

    public function code(): string
    {
        return 'ITEMS_INCOMPLETE';
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
        if ($submission->items === [] && $submission->rawNumericScores !== null) {
            return [$this->fail('detail_typed_scores', field: 'items')];
        }

        $parts = [];
        $missing = ItemCoverage::missingSlots($submission->items);
        if ($missing !== []) {
            $parts[] = $this->text('detail', ['slots' => implode(', ', $missing)]);
        }
        foreach (ItemCoverage::unscorableDimensions($submission->items) as $dimension) {
            $parts[] = $this->text('detail_all_na', ['dimension' => $dimension->value]);
        }

        return $parts === [] ? [] : [$this->failWithDetail(implode($this->text('separator'), $parts), 'items')];
    }
}
