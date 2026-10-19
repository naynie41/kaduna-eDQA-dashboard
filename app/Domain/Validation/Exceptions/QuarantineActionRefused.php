<?php

declare(strict_types=1);

namespace App\Domain\Validation\Exceptions;

use RuntimeException;

/**
 * A quarantine or flag action that cannot go ahead. Rendered as 409 { code, message }
 * (ARCHITECTURE.md §8); the message comes from lang/en/quarantine.php under errors.{code}.
 */
final class QuarantineActionRefused extends RuntimeException
{
    /** @param  array<string, string|int>  $replace */
    private function __construct(
        public readonly string $errorCode,
        array $replace = [],
    ) {
        $message = __("quarantine.errors.{$errorCode}", array_map(strval(...), $replace));

        parent::__construct(is_string($message) ? $message : $errorCode);
    }

    public static function reasonTooShort(): self
    {
        return new self('reason_too_short');
    }

    public static function alreadyResolved(): self
    {
        return new self('already_resolved');
    }

    public static function fieldNotCorrectable(string $field): self
    {
        return new self('field_not_correctable', ['field' => $field]);
    }

    public static function invalidCorrection(string $field): self
    {
        return new self('invalid_correction', ['field' => $field]);
    }

    public static function mixedRules(): self
    {
        return new self('mixed_rules');
    }

    public static function unknownAction(string $action): self
    {
        return new self('unknown_action', ['action' => $action]);
    }

    public static function emptyBulk(): self
    {
        return new self('empty_bulk');
    }

    public static function recordNotFound(int $id): self
    {
        return new self('record_not_found', ['id' => $id]);
    }

    public static function flagNotFound(string $code): self
    {
        return new self('flag_not_found', ['code' => $code]);
    }

    public static function notASoftFlag(string $code): self
    {
        return new self('not_a_soft_flag', ['code' => $code]);
    }
}
