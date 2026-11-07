<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\OwnerSplit;
use Illuminate\Http\Request;

/**
 * The Public / Private split.
 *
 * @property OwnerSplit $resource
 */
final class OwnerSplitResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public' => GroupScoresResource::one($this->resource->public),
            'private' => GroupScoresResource::one($this->resource->private),
        ];
    }
}
