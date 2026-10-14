<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quarantine;

use App\Domain\Validation\Actions\CorrectAndReprocess;
use App\Domain\Validation\Actions\RejectQuarantinedRecord;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quarantine\ResolveQuarantineRequest;
use App\Http\Resources\Quarantine\QuarantineResolutionResource;
use App\Models\User;

/** POST /api/quarantine/{record}/resolve. Refusals render as 409 (bootstrap/app.php). */
final class ResolveQuarantineController extends Controller
{
    public function __invoke(ResolveQuarantineRequest $request, QuarantinedRecord $record, CorrectAndReprocess $correct, RejectQuarantinedRecord $reject): QuarantineResolutionResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $reason = $request->string('reason')->toString();

        $request->string('action')->toString() === 'correct'
            ? $correct->execute($record, $request->corrections(), $actor, $reason)
            : $reject->execute($record, $actor, $reason);

        return new QuarantineResolutionResource($record->refresh()->load('submission.assessment'));
    }
}
