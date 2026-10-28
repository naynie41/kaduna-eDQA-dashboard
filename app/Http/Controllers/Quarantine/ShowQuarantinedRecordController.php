<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quarantine;

use App\Domain\Validation\Models\QuarantinedRecord;
use App\Domain\Validation\Queries\QuarantineQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\Quarantine\DataIssueDetailResource;

/** GET /api/quarantine/{record}: one record for the Data issues drawer (D-57). */
final class ShowQuarantinedRecordController extends Controller
{
    public function __invoke(QuarantinedRecord $record, QuarantineQuery $quarantine): DataIssueDetailResource
    {
        $record->load(['submission:id,instance_id,form_ref,received_at,status,payload', 'round:id,year,quarter']);

        return new DataIssueDetailResource($record, $quarantine->facilityNames([$record->facility_ref]));
    }
}
