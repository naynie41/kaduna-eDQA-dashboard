<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Scoring\DTOs\ScoreValue;
use Illuminate\Http\Request;

/**
 * A score as the page shows it: value, display, headline, band.
 *
 * @property ScoreValue $resource
 */
final class ScoreValueResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'value' => $this->resource->value,
            'display' => $this->resource->display,
            'headline' => $this->resource->headline,
            'band' => $this->resource->band?->value,
            'bandLabel' => $this->resource->bandLabel,
        ];
    }
}
