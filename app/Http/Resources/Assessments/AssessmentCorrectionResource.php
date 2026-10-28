<?php

declare(strict_types=1);

namespace App\Http\Resources\Assessments;

use App\Domain\Assessment\DTOs\CorrectionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A correction's answer: each changed item (old and new result), the new times, the
 * assessment's status and flags after the soft rules ran again, and the round whose rescore is
 * queued. The drawer then reloads the facility for the new scores.
 *
 * @property CorrectionResult $resource
 */
final class AssessmentCorrectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $result = $this->resource;

        return [
            'assessment_id' => $result->assessment->id,
            'status' => $result->assessment->status,
            'flags' => $result->assessment->flags,
            'changes' => $result->changes,
            'times' => $result->times,
            'rescore_round' => ['id' => $result->round->id, 'year' => $result->round->year, 'quarter' => $result->round->quarter],
        ];
    }
}
