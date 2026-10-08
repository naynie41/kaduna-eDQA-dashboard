<?php

declare(strict_types=1);

namespace App\Domain\Validation\DTOs;

use App\Domain\Round\Enums\RoundStatus;
use Carbon\CarbonImmutable;

/** A round as the validation context holds it. The window dates are inclusive local days. */
final readonly class RoundSnapshot
{
    public function __construct(
        public int $id,
        public int $year,
        public int $quarter,
        public RoundStatus $status,
        public CarbonImmutable $windowStart,
        public CarbonImmutable $windowEnd,
    ) {}

    public function isOpen(): bool
    {
        return $this->status === RoundStatus::Open;
    }

    /** @param  CarbonImmutable  $day  a local calendar day (time ignored) */
    public function contains(CarbonImmutable $day): bool
    {
        $date = $day->toDateString();

        return $date >= $this->windowStart->toDateString() && $date <= $this->windowEnd->toDateString();
    }

    /** Orders rounds in time: 2025 Q4 < 2026 Q1. */
    public function sequence(): int
    {
        return $this->year * 4 + $this->quarter;
    }
}
