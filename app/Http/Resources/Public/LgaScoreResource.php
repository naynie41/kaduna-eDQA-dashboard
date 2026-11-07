<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\LgaScore;
use Illuminate\Http\Request;

/**
 * An LGA and its overall (weakest LGAs, the plan's clear list).
 *
 * @property LgaScore $resource
 */
final class LgaScoreResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'lgaId' => $this->resource->lgaId,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'facilities' => $this->resource->facilities,
            'overall' => self::score($this->resource->overall),
        ];
    }
}
