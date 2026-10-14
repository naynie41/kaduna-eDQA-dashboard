<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quarantine;

use App\Domain\Validation\Queries\QuarantineQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quarantine\QuarantineIndexRequest;
use App\Http\Resources\Quarantine\QuarantinedRecordResource;
use App\Http\Resources\Quarantine\QuarantineGroupResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** GET /api/quarantine: flat (paginated) or grouped by rule (?group=rule). */
final class QuarantineIndexController extends Controller
{
    public function __invoke(QuarantineIndexRequest $request, QuarantineQuery $query): AnonymousResourceCollection
    {
        return $request->grouped()
            ? QuarantineGroupResource::collection($query->countsByRule($request->filters()))
            : QuarantinedRecordResource::collection($query->paginate($request->filters()));
    }
}
