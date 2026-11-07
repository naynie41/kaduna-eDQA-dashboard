<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\LgaRow;
use Illuminate\Http\Request;

/**
 * One LGA tile or table row.
 *
 * @property LgaRow $resource
 */
final class LgaRowResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'lgaId' => $this->resource->lgaId,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'facilities' => $this->resource->facilities,
            'availability' => self::score($this->resource->availability),
            'consistency' => self::score($this->resource->consistency),
            'validity' => self::score($this->resource->validity),
            'm1' => self::score($this->resource->m1),
            'm2' => self::score($this->resource->m2),
            'm3' => self::score($this->resource->m3),
            'overall' => self::score($this->resource->overall),
        ];
    }
}
