<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Laravel\Fortify\RecoveryCode;

/**
 * 2FA recovery codes: shown to the user once, stored only as SHA-256 hashes (SECURITY.md §2).
 *
 * Fortify itself stores them encrypted and can show them again. A fast hash is safe here: each
 * code is two 10-character random strings (~119 bits), far beyond brute force.
 */
final class RecoveryCodes
{
    public const COUNT = 8;

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * @param  list<string>  $codes
     * @return string JSON list of hashes, as stored in users.two_factor_recovery_codes
     */
    public static function store(array $codes): string
    {
        return json_encode(array_map(self::hash(...), $codes), JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string> plain codes, to show once
     */
    public static function generate(): array
    {
        $codes = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $codes[] = RecoveryCode::generate();
        }

        return $codes;
    }
}
