<?php

declare(strict_types=1);

namespace App\Http\Controllers\Submissions;

use App\Domain\Ingestion\Actions\ReviewSourceDeletion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Submissions\ReviewDeletionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * "Mark reviewed" on a deletion at source (D-62): the Data sources page's deletions list. Takes the
 * submission's id only: raw submissions stay inside Ingestion (CLAUDE.md hard rule 6), which loads
 * the row (an unknown id is a 404).
 */
final class ReviewDeletionController extends Controller
{
    public function __invoke(ReviewDeletionRequest $request, int $submission, ReviewSourceDeletion $review): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $reviewed = $review->execute($submission, $actor, $request->string('reason')->toString());

        return response()->json(['data' => $reviewed]);
    }
}
