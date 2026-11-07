<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\GroupScores;
use Illuminate\Http\Request;

/**
 * One side of the Public / Private split.
 *
 * @property GroupScores $resource
 */
final class GroupScoresResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'facilities' => $this->resource->facilities,
            'availability' => self::score($this->resource->availability),
            'consistency' => self::score($this->resource->consistency),
            'validity' => self::score($this->resource->validity),
            'overall' => self::score($this->resource->overall),
        ];
    }
}
