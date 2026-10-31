<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Ingestion\Models\PullRun;
use App\Http\Controllers\Controller;
use App\Http\Resources\Sources\PullRunResource;

/** GET /api/pulls/{runId} (ARCHITECTURE.md §8): one run's progress, any source and any trigger. */
final class PullRunController extends Controller
{
    public function __invoke(int $run): PullRunResource
    {
        return new PullRunResource(PullRun::query()->with('source:id,key')->findOrFail($run));
    }
}
