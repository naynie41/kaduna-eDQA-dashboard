<?php

declare(strict_types=1);

namespace App\Support\Config;

/**
 * Reads a numeric env value that must be a positive whole number (a page size, a throttle in
 * seconds). Anything else (blank, 0, negative, a decimal, text, stray spaces) gives the default,
 * so a typo in .env can never page by 0 or stop throttling a live server.
 */
final class EnvNumber
{
    public static function positiveInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : $default;
        }
        if (! is_string($value) || preg_match('/^[0-9]{1,9}$/D', $value) !== 1) {
            return $default;
        }

        return (int) $value > 0 ? (int) $value : $default;
    }
}
