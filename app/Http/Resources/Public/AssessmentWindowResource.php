<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\AssessmentWindow;
use Illuminate\Http\Request;

/**
 * Monitor: one round's window, first and last visit dates and count.
 *
 * @property AssessmentWindow $resource
 */
final class AssessmentWindowResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'roundId' => $this->resource->roundId,
            'label' => $this->resource->label,
            'windowStart' => $this->resource->windowStart,
            'windowEnd' => $this->resource->windowEnd,
            'firstVisit' => $this->resource->firstVisit,
            'lastVisit' => $this->resource->lastVisit,
            'assessments' => $this->resource->assessments,
        ];
    }
}
