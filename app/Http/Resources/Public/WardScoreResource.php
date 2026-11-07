<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\WardScore;
use Illuminate\Http\Request;

/**
 * A ward and its overall (weakest wards in an LGA).
 *
 * @property WardScore $resource
 */
final class WardScoreResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'wardId' => $this->resource->wardId,
            'name' => $this->resource->name,
            'facilities' => $this->resource->facilities,
            'overall' => self::score($this->resource->overall),
        ];
    }
}
