<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\StateSummary;
use Illuminate\Http\Request;

/**
 * The Home hero.
 *
 * @property StateSummary $resource
 */
final class StateSummaryResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'overall' => self::score($this->resource->overall),
            'facilitiesVisited' => $this->resource->facilitiesVisited,
            'lgasCovered' => $this->resource->lgasCovered,
            'facilitiesStrong' => $this->resource->facilitiesStrong,
            'facilitiesWeak' => $this->resource->facilitiesWeak,
            'strongAt' => $this->resource->strongAt,
            'weakBelow' => $this->resource->weakBelow,
        ];
    }
}
