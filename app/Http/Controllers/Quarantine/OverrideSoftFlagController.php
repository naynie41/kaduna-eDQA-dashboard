<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quarantine;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Validation\Actions\OverrideSoftFlag;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quarantine\OverrideSoftFlagRequest;
use App\Http\Resources\AssessmentFlagsResource;
use App\Models\User;

/** POST /api/assessments/{assessment}/flags/{code}/override. Refusals render as 409. */
final class OverrideSoftFlagController extends Controller
{
    public function __invoke(OverrideSoftFlagRequest $request, Assessment $assessment, string $code, OverrideSoftFlag $override): AssessmentFlagsResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new AssessmentFlagsResource($override->execute($assessment, $code, $actor, $request->string('reason')->toString()));
    }
}
