<?php

declare(strict_types=1);

namespace App\Http\Resources\Quarantine;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One rule group on the Data issues page: the code, its name and the record count computed by
 * QuarantineQuery::countsByRule().
 *
 * @property array{code: string, count: int} $resource
 */
final class QuarantineGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource['code'],
            'name' => __('validation_rules.'.$this->resource['code'].'.name'),
            'count' => $this->resource['count'],
        ];
    }
}
