<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Exceptions;

use InvalidArgumentException;

/**
 * A scoring rule config the calculator cannot apply exactly, so it is refused rather than scored
 * with a guess. Rendered as 409 { code, message }; the message comes from lang/en/scoring.php.
 */
final class InvalidRuleConfig extends InvalidArgumentException
{
    /** @param  array<string, string|int|float>  $replace */
    private function __construct(
        public readonly string $errorCode,
        array $replace = [],
    ) {
        $message = __("scoring.errors.{$errorCode}", array_map(strval(...), $replace));

        parent::__construct(is_string($message) ? $message : $errorCode);
    }

    /** @param  array<string, string|int|float>  $replace */
    public static function because(string $errorCode, array $replace = []): self
    {
        return new self($errorCode, $replace);
    }
}
