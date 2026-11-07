<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\FacilityRow;
use Illuminate\Http\Request;

/**
 * One row of the facility view: master-list code and place, never coordinates (Q-20).
 *
 * @property FacilityRow $resource
 */
final class FacilityRowResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'facilityId' => $this->resource->facilityId,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'lga' => $this->resource->lga,
            'ward' => $this->resource->ward,
            'ownership' => $this->resource->ownership->value,
            'level' => $this->resource->level->value,
            'roundLabel' => $this->resource->roundLabel,
            'assessedOn' => $this->resource->assessedOn,
            'm1' => self::score($this->resource->m1),
            'm2' => self::score($this->resource->m2),
            'm3' => self::score($this->resource->m3),
            'score' => self::score($this->resource->score),
            'overall' => self::score($this->resource->overall),
        ];
    }
}
