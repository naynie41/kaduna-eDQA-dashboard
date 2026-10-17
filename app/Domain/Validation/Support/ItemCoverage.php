<?php

declare(strict_types=1);

namespace App\Domain\Validation\Support;

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Scoring\Enums\Dimension;

/**
 * Which of the nine dimension × month slots a submission's items cover. One definition shared by
 * ITEMS_INCOMPLETE (which reports gaps) and SCORE_RANGE (which only scores complete sets, so
 * ScoreCalculator never throws).
 *
 * An all-N/A slot is present: the calculator excludes it. A dimension with no applicable item in
 * any of its present slots cannot be scored at all. An answer the form does not allow ('invalid')
 * is a missing answer: it covers no slot, and any one makes the set incomplete.
 */
final class ItemCoverage
{
    /**
     * Slots with no valid item, as "consistency_m2", in dimension then month order.
     *
     * @param  list<ItemResponseData>  $items
     * @return list<string>
     */
    public static function missingSlots(array $items): array
    {
        $present = [];
        foreach (self::valid($items) as $item) {
            $present[self::slotName($item->dimension, $item->monthSlot)] = true;
        }

        $missing = [];
        foreach (Dimension::cases() as $dimension) {
            foreach ([1, 2, 3] as $month) {
                if (! isset($present[self::slotName($dimension, $month)])) {
                    $missing[] = self::slotName($dimension, $month);
                }
            }
        }

        return $missing;
    }

    /**
     * Dimensions that have valid items but not one applicable item (every present slot all N/A).
     *
     * @param  list<ItemResponseData>  $items
     * @return list<Dimension>
     */
    public static function unscorableDimensions(array $items): array
    {
        $hasItems = [];
        $hasApplicable = [];
        foreach (self::valid($items) as $item) {
            $hasItems[$item->dimension->value] = true;
            if ($item->isApplicable && $item->value !== 'na') {
                $hasApplicable[$item->dimension->value] = true;
            }
        }

        return array_values(array_filter(
            Dimension::cases(),
            fn (Dimension $d): bool => isset($hasItems[$d->value]) && ! isset($hasApplicable[$d->value]),
        ));
    }

    /**
     * Items whose answer the form does not allow, in the order they came.
     *
     * @param  list<ItemResponseData>  $items
     * @return list<ItemResponseData>
     */
    public static function invalidItems(array $items): array
    {
        return array_values(array_filter($items, fn (ItemResponseData $item): bool => $item->value === ItemResponseData::INVALID));
    }

    /** @param  list<ItemResponseData>  $items */
    public static function isComplete(array $items): bool
    {
        return self::missingSlots($items) === [] && self::unscorableDimensions($items) === [] && self::invalidItems($items) === [];
    }

    public static function slotName(Dimension $dimension, int $month): string
    {
        return "{$dimension->value}_m{$month}";
    }

    /**
     * @param  list<ItemResponseData>  $items
     * @return list<ItemResponseData>
     */
    private static function valid(array $items): array
    {
        return array_values(array_filter($items, fn (ItemResponseData $item): bool => $item->value !== ItemResponseData::INVALID));
    }
}
