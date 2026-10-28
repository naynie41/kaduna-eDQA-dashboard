<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Support;

use App\Domain\Scoring\Enums\Dimension;

/**
 * An item response named as the form names its question: "avail_m2_reporting_rate" is the
 * Availability, month slot 2, reporting_rate item (the scored-question pattern of the field
 * maps). Corrections and the facility drawer use it, so an administrator reads the same name
 * the form shows.
 */
final class ItemKey
{
    private const PREFIXES = ['availability' => 'avail', 'consistency' => 'consist', 'validity' => 'valid'];

    public static function make(Dimension $dimension, int $slot, string $code): string
    {
        return self::PREFIXES[$dimension->value]."_m{$slot}_{$code}";
    }

    /** @return array{dimension: Dimension, slot: int, code: string}|null null when it is not an item key */
    public static function parse(string $key): ?array
    {
        if (preg_match('/^(avail|consist|valid)_m([1-3])_([a-z0-9_]{1,64})$/', $key, $match) !== 1) {
            return null;
        }

        return [
            'dimension' => Dimension::from((string) array_search($match[1], self::PREFIXES, true)),
            'slot' => (int) $match[2],
            'code' => $match[3],
        ];
    }
}
