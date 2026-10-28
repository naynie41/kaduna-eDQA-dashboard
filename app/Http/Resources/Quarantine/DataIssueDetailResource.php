<?php

declare(strict_types=1);

namespace App\Http\Resources\Quarantine;

use App\Domain\Validation\Models\QuarantinedRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * One record for the Data issues drawer (D-57): the row, the parsed values as the parser read
 * them, the raw ODK payload exactly as stored (shown, never editable: hard rule 8), and the
 * record's history from the audit log, newest first: who, when, what changed and why. The
 * corrections history is the "corrected" entries of that log. IP and user agent stay in the log.
 *
 * @mixin QuarantinedRecord
 */
final class DataIssueDetailResource extends JsonResource
{
    /** @param  array<string, string>  $facilityNames */
    public function __construct(QuarantinedRecord $record, private readonly array $facilityNames = [])
    {
        parent::__construct($record);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $activity = Activity::query()->forSubject($this->resource)->with('causer:id,name')->latest('id')->limit(100)->get();

        return [
            ...(new DataIssueRowResource($this->resource, $this->facilityNames))->toArray($request),
            'parsed' => (object) (is_array($this->parsed) ? $this->parsed : []),
            'payload' => (object) $this->submission->payload, // the stored original, never the record's copy
            'history' => $activity->map(fn (Activity $entry): array => [
                'id' => $entry->id,
                'event' => (string) $entry->event,
                'at' => $entry->created_at?->toIso8601String(),
                'by' => $entry->causer?->getAttribute('name'),
                'reason' => $entry->properties['reason'] ?? null,
                'old' => (object) ($entry->properties['old'] ?? []),
                'new' => (object) ($entry->properties['attributes'] ?? []),
            ])->values()->all(),
        ];
    }
}
