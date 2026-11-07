<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\DimensionScores;
use Illuminate\Http\Request;

/**
 * The three dimensions and overall.
 *
 * @property DimensionScores $resource
 */
final class DimensionScoresResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'availability' => self::score($this->resource->availability),
            'consistency' => self::score($this->resource->consistency),
            'validity' => self::score($this->resource->validity),
            'overall' => self::score($this->resource->overall),
            'weakest' => $this->resource->weakest?->value,
        ];
    }
}
