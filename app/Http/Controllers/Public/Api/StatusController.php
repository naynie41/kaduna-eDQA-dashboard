<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Api;

use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Reporting\Queries\FiguresUpdatedQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\StatusResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/public/status (ARCHITECTURE.md §8, D-81): "Figures last updated …" only. */
final class StatusController extends Controller
{
    public function __invoke(Request $request, FiguresUpdatedQuery $figures): JsonResponse
    {
        return StatusResource::make(['at' => $figures->at(ReportAudience::of($request->user()))])->response();
    }
}
