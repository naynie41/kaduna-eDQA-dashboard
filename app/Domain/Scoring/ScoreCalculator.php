<?php

declare(strict_types=1);

namespace App\Domain\Scoring;

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\DTOs\ScoreCard;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Exceptions\IncompleteAssessmentException;

/**
 * Derives every score from item responses (ARCHITECTURE.md §7; CLAUDE.md hard rule 2):
 *
 *   slot      = Σ(weight × pass) / Σ(weight × applicable) × 100, N/A excluded from both;
 *               null when no item is applicable
 *   dimension = mean of its non-null slots
 *   overall   = mean of the three dimensions
 *
 * Pure: no database, facades or config(). Full float precision, no rounding. Items are summed
 * in a fixed order (by item code), so the result never depends on the order they arrive in.
 */
final class ScoreCalculator
{
    /** @param  list<ItemResponseData>  $items */
    public function calculate(array $items, RuleConfig $rules): ScoreCard
    {
        $bySlot = [];
        foreach ($items as $item) {
            $bySlot[$item->dimension->value][$item->monthSlot][] = $item;
        }

        $slots = [];
        $dimensions = [];
        $exclusions = [];

        foreach (Dimension::cases() as $dimension) {
            $scored = [];
            foreach ([1, 2, 3] as $month) {
                $score = $this->slotScore($bySlot[$dimension->value][$month] ?? [], $rules);
                $slots[$dimension->value][$month] = $score;

                if ($score === null) {
                    $exclusions[] = "SLOT_ALL_NA:{$dimension->value}:{$month}";
                } else {
                    $scored[] = $score;
                }
            }

            if ($scored === []) {
                throw IncompleteAssessmentException::forDimension($dimension);
            }
            $dimensions[$dimension->value] = array_sum($scored) / count($scored);
        }

        return new ScoreCard($slots, $dimensions, array_sum($dimensions) / count($dimensions), $exclusions);
    }

    /** @param  list<ItemResponseData>  $items */
    private function slotScore(array $items, RuleConfig $rules): ?float
    {
        usort($items, fn (ItemResponseData $a, ItemResponseData $b): int => [$a->itemCode, $a->value] <=> [$b->itemCode, $b->value]);

        $passed = 0.0;
        $applicable = 0.0;
        foreach ($items as $item) {
            // N/A by value or by flag: left out of both sums.
            if (! $item->isApplicable || $item->value === 'na') {
                continue;
            }
            $weight = $rules->weightFor($item->dimension->value, $item->itemCode);
            $applicable += $weight;
            if ($item->value === 'pass') {
                $passed += $weight;
            }
        }

        // Divide first, then × 100 (CLAUDE.md §5): passed ≤ applicable even after float
        // rounding, so the ratio is ≤ 1 and the score can never exceed 100. (× 100 first can give
        // 100.00000000000001 for weighted all-pass slots.)
        return $applicable > 0 ? $passed / $applicable * 100 : null;
    }
}
