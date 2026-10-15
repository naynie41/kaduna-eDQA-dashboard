<?php

declare(strict_types=1);

namespace App\Domain\Round\Exceptions;

use RuntimeException;

/**
 * A round lifecycle action that cannot go ahead. Rendered as 409 { code, message }; the message
 * comes from lang/en/rounds.php under errors.{code}.
 */
final class RoundActionRefused extends RuntimeException
{
    /** @param  array<string, string|int>  $replace */
    private function __construct(
        public readonly string $errorCode,
        array $replace = [],
    ) {
        $message = __("rounds.errors.{$errorCode}", array_map(strval(...), $replace));

        parent::__construct(is_string($message) ? $message : $errorCode);
    }

    public static function roundExists(string $round): self
    {
        return new self('round_exists', ['round' => $round]);
    }

    public static function windowOrder(): self
    {
        return new self('window_order');
    }

    public static function windowOutsideQuarter(string $round, string $start, string $end, string $latest, int $grace): self
    {
        return new self('window_outside_quarter', ['round' => $round, 'start' => $start, 'end' => $end, 'latest' => $latest, 'grace' => $grace]);
    }

    public static function windowOverlap(string $round): self
    {
        return new self('window_overlap', ['round' => $round]);
    }

    public static function assessmentsOutsideWindow(int $count): self
    {
        return new self('assessments_outside_window', ['count' => $count]);
    }

    public static function roundClosed(): self
    {
        return new self('round_closed');
    }

    public static function alreadyClosed(): self
    {
        return new self('already_closed');
    }

    public static function notClosed(): self
    {
        return new self('not_closed');
    }

    public static function notFullyScored(int $count): self
    {
        return new self('not_fully_scored', ['count' => $count]);
    }

    public static function reasonTooShort(): self
    {
        return new self('reason_too_short');
    }
}
