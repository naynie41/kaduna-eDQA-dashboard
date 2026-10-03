<?php

declare(strict_types=1);

namespace App\Support\Config;

/**
 * Reads an env flag that must be ON unless explicitly turned off, so a missing or empty value
 * fails safe (e.g. the session cookie stays Secure unless local http development says false).
 */
final class EnvFlag
{
    public static function enabledUnlessFalse(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
