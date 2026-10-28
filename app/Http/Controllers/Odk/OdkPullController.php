<?php

declare(strict_types=1);

namespace App\Http\Controllers\Odk;

use App\Domain\Ingestion\Actions\StartManualPull;
use App\Domain\Ingestion\Models\PullRun;
use App\Http\Controllers\Controller;
use App\Http\Resources\Odk\OdkPullRunResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Pull now" and its progress (ARCHITECTURE.md §8). POST queues a manual pull (audited, 6 a minute
 * per administrator) and answers 202 with its run_id, or the run already in progress
 * (already_running: true). GET follows a run, any trigger.
 */
final class OdkPullController extends Controller
{
    public function store(Request $request, StartManualPull $start): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        ['run' => $run, 'running' => $running] = $start->execute($actor);

        return response()->json(['run_id' => $run->id, 'already_running' => $running], 202);
    }

    public function show(int $run): OdkPullRunResource
    {
        return new OdkPullRunResource(PullRun::query()->findOrFail($run));
    }
}
