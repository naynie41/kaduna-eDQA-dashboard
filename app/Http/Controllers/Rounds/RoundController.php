<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rounds;

use App\Domain\Round\Actions\CloseRound;
use App\Domain\Round\Actions\CreateRound;
use App\Domain\Round\Actions\ReopenRound;
use App\Domain\Round\Actions\UpdateRoundWindow;
use App\Domain\Round\Models\Round;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rounds\ReopenRoundRequest;
use App\Http\Requests\Rounds\StoreRoundRequest;
use App\Http\Requests\Rounds\UpdateRoundWindowRequest;
use App\Http\Resources\Rounds\RoundResource;
use App\Http\Resources\Scoring\RescoreRunResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Bus;

/**
 * /api/rounds (ARCHITECTURE.md §8): the Rounds admin page. Refusals (RoundActionRefused) render
 * as 409 { code, message }; a saved window comes back with its warnings.
 */
final class RoundController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RoundResource::collection(Round::query()->orderByDesc('year')->orderByDesc('quarter')->get());
    }

    public function store(StoreRoundRequest $request, CreateRound $create): JsonResponse
    {
        $result = $create->execute(
            $request->integer('year'),
            $request->integer('quarter'),
            self::day($request, 'window_start'),
            self::day($request, 'window_end'),
            self::actor($request),
        );

        return (new RoundResource($result->round))->additional(['warnings' => $result->warnings])
            ->response()->setStatusCode(201);
    }

    public function update(UpdateRoundWindowRequest $request, Round $round, UpdateRoundWindow $update): RoundResource
    {
        $result = $update->execute($round, self::day($request, 'window_start'), self::day($request, 'window_end'), self::actor($request));

        return (new RoundResource($result->round))->additional(['warnings' => $result->warnings]);
    }

    public function close(Request $request, Round $round, CloseRound $close): RoundResource
    {
        return new RoundResource($close->execute($round, self::actor($request)));
    }

    public function reopen(ReopenRoundRequest $request, Round $round, ReopenRound $reopen): RoundResource
    {
        $result = $reopen->execute($round, self::actor($request), $request->string('reason')->toString(), $request->boolean('rescore'));
        $run = $result->rescore;

        return (new RoundResource($result->round))->additional([
            'rescore' => $run === null ? null : new RescoreRunResource($run, Bus::findBatch((string) $run->batch_id)),
        ]);
    }

    private static function day(Request $request, string $key): CarbonImmutable
    {
        return CarbonImmutable::parse($request->string($key)->toString())->startOfDay();
    }

    private static function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
