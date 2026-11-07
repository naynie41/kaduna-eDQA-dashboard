<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\MonthSlotPoint;
use Illuminate\Http\Request;

/**
 * One month of a round.
 *
 * @property MonthSlotPoint $resource
 */
final class MonthSlotPointResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'roundId' => $this->resource->roundId,
            'year' => $this->resource->year,
            'quarter' => $this->resource->quarter,
            'slot' => $this->resource->slot,
            'month' => $this->resource->month,
            'label' => $this->resource->label,
            'chartLabel' => $this->resource->chartLabel,
            'availability' => self::score($this->resource->availability),
            'consistency' => self::score($this->resource->consistency),
            'validity' => self::score($this->resource->validity),
            'overall' => self::score($this->resource->overall),
        ];
    }
}
