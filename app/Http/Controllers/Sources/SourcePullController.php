<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Ingestion\Actions\StartManualPull;
use App\Domain\Ingestion\Exceptions\SourceRefused;
use App\Domain\Ingestion\Exceptions\SourceUnavailable;
use App\Domain\Ingestion\Models\PullRun;
use App\Http\Controllers\Controller;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Pull now", "Pull all" and "Reconcile" (ARCHITECTURE.md §8), each audited and limited per
 * administrator and per source (SECURITY.md §8). All answer 202 with the run to follow on
 * GET /api/pulls/{runId}, or the one already running (already_running: true).
 *
 * - A key not in config: 404.
 * - A source not configured or switched off, or no source on for pull-all: 409 { code, message }.
 */
final class SourcePullController extends Controller
{
    public function store(Request $request, StartManualPull $start, string $key): JsonResponse
    {
        return $this->answer(fn (): array => self::run($start->execute(self::actor($request), $key)));
    }

    public function reconcile(Request $request, StartManualPull $start, string $key): JsonResponse
    {
        return $this->answer(fn (): array => self::run($start->reconcile(self::actor($request), $key)));
    }

    public function all(Request $request, StartManualPull $start): JsonResponse
    {
        return $this->answer(fn (): array => ['runs' => array_map(
            fn (array $started): array => ['source' => $started['source'], ...self::run($started)],
            $start->all(self::actor($request)),
        )]);
    }

    /** @param  Closure(): array<string, mixed>  $start */
    private function answer(Closure $start): JsonResponse
    {
        try {
            return response()->json($start(), 202);
        } catch (SourceRefused) {
            abort(404);
        } catch (SourceUnavailable $e) {
            return response()->json(['code' => $e->code(), 'message' => $e->getMessage()], 409);
        }
    }

    /**
     * @param  array{run: PullRun, running: bool}  $started
     * @return array{run_id: int, already_running: bool}
     */
    private static function run(array $started): array
    {
        return ['run_id' => $started['run']->id, 'already_running' => $started['running']];
    }

    private static function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
