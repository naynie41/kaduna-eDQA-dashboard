<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/sources (ARCHITECTURE.md §8): every source in config order (SourcesPayload), then the
 * open rounds across every source.
 */
final class SourceIndexController extends Controller
{
    public function __invoke(SourcesPayload $sources, OpenRoundsPayload $rounds): JsonResponse
    {
        return response()->json(['data' => [
            'sources' => $sources->build(),
            'open_rounds' => $rounds->build(),
        ]]);
    }
}
