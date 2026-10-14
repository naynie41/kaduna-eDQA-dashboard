<?php

declare(strict_types=1);

namespace App\Domain\Validation\Support;

use App\Domain\Validation\Exceptions\QuarantineActionRefused;

/**
 * The mandatory typed justification (SECURITY.md §4): at least 10 characters once trimmed,
 * enforced in the Action as well as the FormRequest.
 */
final class Reason
{
    public const MIN_LENGTH = 10;

    public static function require(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_LENGTH) {
            throw QuarantineActionRefused::reasonTooShort();
        }

        return $reason;
    }
}
