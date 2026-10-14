<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scoring;

use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Actions\RescoreRound;
use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Domain\Scoring\Models\RescoreRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scoring\RescoreRoundRequest;
use App\Http\Resources\Scoring\RescoreRunResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Bus;

/**
 * POST /api/rounds/{round}/rescore (reason required, audited) starts a rescore and answers 202
 * with its batch id; GET /api/rescores/{batchId} reports progress. A closed round is a 409
 * (RescoreRefused).
 */
final class RescoreController extends Controller
{
    public function store(RescoreRoundRequest $request, Round $round, RescoreRound $rescore): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $run = $rescore->start($round, RescoreTrigger::Manual, $actor, $request->string('reason')->toString());

        return (new RescoreRunResource($run, Bus::findBatch((string) $run->batch_id)))
            ->response()->setStatusCode(202);
    }

    public function show(string $batchId): RescoreRunResource
    {
        $run = RescoreRun::query()->where('batch_id', $batchId)->firstOrFail();

        return new RescoreRunResource($run, Bus::findBatch($batchId));
    }
}
