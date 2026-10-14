<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Exceptions;

use RuntimeException;

/**
 * A rescore that cannot start. Rendered as 409 { code, message }; the message comes from
 * lang/en/scoring.php under errors.{code}.
 */
final class RescoreRefused extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
    ) {
        $message = __("scoring.errors.{$errorCode}");

        parent::__construct(is_string($message) ? $message : $errorCode);
    }

    /** D-32: a closed round keeps its pinned scores; reopen it (with a reason) to rescore. */
    public static function roundClosed(): self
    {
        return new self('round_closed');
    }

    public static function reasonTooShort(): self
    {
        return new self('reason_too_short');
    }
}
