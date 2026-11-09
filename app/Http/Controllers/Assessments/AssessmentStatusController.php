<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assessments;

use App\Domain\Ingestion\Actions\WithdrawAssessment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Submissions\ReviewDeletionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** "Withdraw assessment" and "Restore" on the Data issues page's Deleted at source tab (D-62): a reason each. */
final class AssessmentStatusController extends Controller
{
    public function withdraw(ReviewDeletionRequest $request, int $assessment, WithdrawAssessment $action): JsonResponse
    {
        return response()->json(['data' => $action->withdraw($assessment, self::actor($request), $request->string('reason')->toString())]);
    }

    public function restore(ReviewDeletionRequest $request, int $assessment, WithdrawAssessment $action): JsonResponse
    {
        return response()->json(['data' => $action->restore($assessment, self::actor($request), $request->string('reason')->toString())]);
    }

    private static function actor(ReviewDeletionRequest $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
