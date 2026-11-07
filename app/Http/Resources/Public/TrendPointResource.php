<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\TrendPoint;
use Illuminate\Http\Request;

/**
 * One round on the quarterly trend.
 *
 * @property TrendPoint $resource
 */
final class TrendPointResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'roundId' => $this->resource->roundId,
            'year' => $this->resource->year,
            'quarter' => $this->resource->quarter,
            'label' => $this->resource->label,
            'overall' => self::score($this->resource->overall),
            'public' => self::score($this->resource->public),
            'private' => self::score($this->resource->private),
            'availability' => self::score($this->resource->availability),
            'consistency' => self::score($this->resource->consistency),
            'validity' => self::score($this->resource->validity),
        ];
    }
}
