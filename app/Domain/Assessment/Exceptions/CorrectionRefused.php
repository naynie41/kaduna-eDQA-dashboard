<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Exceptions;

use RuntimeException;

/**
 * Why a correction of an assessment was refused (D-58), answered as 409 { code, message } with the
 * message from lang/en/assessments.php. Nothing is written when one is thrown.
 */
final class CorrectionRefused extends RuntimeException
{
    /** @param  array<string, string|int>  $replace */
    private function __construct(
        public readonly string $errorCode,
        array $replace = [],
    ) {
        $message = __("assessments.corrections.errors.{$errorCode}", array_map(strval(...), $replace));

        parent::__construct(is_string($message) ? $message : $errorCode);
    }

    /** D-32: a closed round's scores are pinned; it must be reopened, with a reason, first. */
    public static function roundClosed(string $round): self
    {
        return new self('round_closed', ['round' => $round]);
    }

    public static function notAccepted(string $status): self
    {
        return new self('not_accepted', ['status' => $status]);
    }

    public static function noChanges(): self
    {
        return new self('no_changes');
    }

    public static function unknownItem(string $key): self
    {
        return new self('unknown_item', ['key' => $key]);
    }

    public static function endBeforeStart(): self
    {
        return new self('end_before_start');
    }

    public static function outsideRound(string $date, string $round, string $start, string $end): self
    {
        return new self('outside_round', ['date' => $date, 'round' => $round, 'start' => $start, 'end' => $end]);
    }

    public static function unscorable(): self
    {
        return new self('unscorable');
    }
}
