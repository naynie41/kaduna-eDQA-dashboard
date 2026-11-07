<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\FacilityPage;
use Illuminate\Http\Request;

/**
 * One page of the facility view.
 *
 * @property FacilityPage $resource
 */
final class FacilityPageResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'rows' => FacilityRowResource::many($this->resource->rows),
            'total' => $this->resource->total,
            'page' => $this->resource->page,
            'perPage' => $this->resource->perPage,
            'lastPage' => $this->resource->lastPage,
            'from' => $this->resource->from,
            'to' => $this->resource->to,
        ];
    }
}
