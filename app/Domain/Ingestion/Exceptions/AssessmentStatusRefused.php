<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

use RuntimeException;

/** Why an assessment cannot be withdrawn or restored: 409 { code, message }. */
final class AssessmentStatusRefused extends RuntimeException
{
    /** @param  array<string, string|int>  $replace */
    private function __construct(
        public readonly string $errorCode,
        array $replace = [],
    ) {
        $message = __("sources.withdrawal.errors.{$errorCode}", array_map(strval(...), $replace));

        parent::__construct(is_string($message) ? $message : $errorCode);
    }

    /** D-32: a closed round's figures are fixed; reopen it, with a reason, first. */
    public static function roundClosed(string $round): self
    {
        return new self('round_closed', ['round' => $round]);
    }

    public static function alreadyWithdrawn(): self
    {
        return new self('already_withdrawn');
    }

    /** Only an assessment whose submission its source no longer returns can be withdrawn here. */
    public static function notDeletedAtSource(): self
    {
        return new self('not_deleted_at_source');
    }

    public static function notWithdrawn(): self
    {
        return new self('not_withdrawn');
    }

    /** The facility has been assessed again in the round since: two cannot count. */
    public static function roundTaken(string $round): self
    {
        return new self('round_taken', ['round' => $round]);
    }
}
