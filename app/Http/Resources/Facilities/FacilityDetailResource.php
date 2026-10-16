<?php

declare(strict_types=1);

namespace App\Http\Resources\Facilities;

use App\Domain\Reporting\DTOs\FacilityDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The facility drawer, as computed by FacilityDetailAggregator (no arithmetic here).
 *
 * @property FacilityDetail $resource
 */
final class FacilityDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
