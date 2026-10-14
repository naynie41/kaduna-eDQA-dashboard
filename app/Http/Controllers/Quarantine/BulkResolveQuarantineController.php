<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quarantine;

use App\Domain\Validation\Actions\BulkResolveQuarantine;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quarantine\BulkResolveQuarantineRequest;
use App\Http\Resources\Quarantine\QuarantineResolutionResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** POST /api/quarantine/bulk-resolve. Refusals render as 409 (bootstrap/app.php). */
final class BulkResolveQuarantineController extends Controller
{
    public function __invoke(BulkResolveQuarantineRequest $request, BulkResolveQuarantine $bulk): AnonymousResourceCollection
    {
        /** @var User $actor */
        $actor = $request->user();

        $records = $bulk->execute(
            $request->ids(),
            $request->string('action')->toString(),
            $request->corrections(),
            $actor,
            $request->string('reason')->toString(),
        );

        return QuarantineResolutionResource::collection(
            QuarantinedRecord::query()->whereKey(array_map(fn (QuarantinedRecord $r): int => $r->id, $records))
                ->with('submission.assessment')->orderBy('id')->get(),
        );
    }
}
