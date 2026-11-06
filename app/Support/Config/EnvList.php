<?php

declare(strict_types=1);

namespace App\Support\Config;

/**
 * Reads a comma-separated env value (an IP allow-list) as a list: entries trimmed, empty ones
 * dropped. Unset or blank gives an empty list.
 */
final class EnvList
{
    /** @return list<string> */
    public static function csv(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value)), fn (string $entry): bool => $entry !== ''));
    }
}
