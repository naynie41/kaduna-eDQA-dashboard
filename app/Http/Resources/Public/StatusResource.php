<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * The public status (D-81): when the public figures last changed, and nothing else. Source health
 * is admin-only.
 *
 * @property array{at: CarbonInterface|null} $resource wrapped, as a null resource would resolve to nothing
 */
final class StatusResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'figures_updated_at' => $this->resource['at']?->toIso8601String(),
        ];
    }
}
