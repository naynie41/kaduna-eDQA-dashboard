<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assessments;

use App\Domain\Assessment\Actions\CorrectAssessment;
use App\Domain\Assessment\Models\Assessment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assessments\CorrectAssessmentRequest;
use App\Http\Resources\Assessments\AssessmentCorrectionResource;
use App\Models\User;

/** POST /api/assessments/{assessment}/corrections: CorrectAssessment, answered with what changed (D-58). */
final class CorrectAssessmentController extends Controller
{
    public function __invoke(CorrectAssessmentRequest $request, Assessment $assessment, CorrectAssessment $correct): AssessmentCorrectionResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new AssessmentCorrectionResource($correct->execute(
            $assessment,
            $request->items(),
            $request->time('started_at'),
            $request->time('ended_at'),
            $actor,
            $request->string('reason')->toString(),
        ));
    }
}
