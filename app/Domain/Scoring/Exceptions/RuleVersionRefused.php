<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Exceptions;

use RuntimeException;

/**
 * A rule-version action that cannot go ahead. Rendered as 409 { code, message }; the message
 * comes from lang/en/scoring.php under errors.{code}.
 */
final class RuleVersionRefused extends RuntimeException
{
    /** @param  array<string, string|int>  $replace */
    private function __construct(
        public readonly string $errorCode,
        array $replace = [],
    ) {
        $message = __("scoring.errors.{$errorCode}", array_map(strval(...), $replace));

        parent::__construct(is_string($message) ? $message : $errorCode);
    }

    public static function draftExists(): self
    {
        return new self('draft_exists');
    }

    public static function notADraft(): self
    {
        return new self('not_a_draft');
    }

    public static function reasonTooShort(): self
    {
        return new self('reason_too_short');
    }

    public static function unknownConfigKey(string $key): self
    {
        return new self('unknown_config_key', ['key' => $key]);
    }
}
