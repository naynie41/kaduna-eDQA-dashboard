<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

/** The facility table's metric switch (ARCHITECTURE.md §9.2.2). */
enum Metric: string
{
    case Availability = 'availability';
    case Consistency = 'consistency';
    case Validity = 'validity';
    case Overall = 'overall';

    /** facility_scores columns for the month cells and the score, for this metric. */
    public function scoreColumn(): string
    {
        return $this->value;
    }

    public function monthColumn(int $slot): string
    {
        return $this === self::Overall ? "m{$slot}" : "{$this->value}_m{$slot}";
    }
}
