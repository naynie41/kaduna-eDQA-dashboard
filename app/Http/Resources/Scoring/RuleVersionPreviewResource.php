<?php

declare(strict_types=1);

namespace App\Http\Resources\Scoring;

use App\Domain\Reporting\DTOs\RuleVersionPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The publish preview, as computed by RuleVersionPreviewAggregator (no arithmetic here).
 *
 * @property RuleVersionPreview $resource
 */
final class RuleVersionPreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
