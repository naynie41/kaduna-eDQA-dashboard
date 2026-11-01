<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\SourceLinks;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;

/**
 * The Data sources page's "Deletions at source" (ARCHITECTURE.md §5.3, §9.3, D-62): submissions a
 * source no longer returns (submissions.deleted_at_source, set by the reconcile sweep), newest
 * first, at most LIMIT, with how many there are. Each with what the portal built from it, so the
 * page can link to it: the assessment (its facility and round) or its quarantine record; and a
 * link to the submission on its source (SourceLinks: configured base URL and validated IDs).
 */
final class DeletionsAtSourceQuery
{
    public const LIMIT = 50;

    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly SourceLinks $links,
    ) {}

    /**
     * @return array{rows: list<array{submission_id: int, source: ?array{key: string, name: string}, instance_id: string, deleted_at: string, facility: ?array{id: int, code: string, name: string}, round: ?array{id: int, year: int, quarter: int}, assessment: ?array{id: int, status: string}, quarantined_record_id: ?int, source_url: ?string, reviewed: ?array{at: string, by: ?string}}>, total: int}
     */
    public function list(): array
    {
        $query = Submission::query()->whereNotNull('deleted_at_source');
        $total = (clone $query)->count();

        $submissions = $query
            ->with([
                'assessment:id,submission_id,round_id,facility_id,status',
                'assessment.facility:id,code,name',
                'assessment.round:id,year,quarter',
                'quarantinedRecords' => fn ($q) => $q->select(['id', 'submission_id', 'round_id', 'status'])->with('round:id,year,quarter')->orderByDesc('id'),
            ])
            ->orderByDesc('deleted_at_source')->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'source_id', 'external_id', 'instance_id', 'deleted_at_source', 'deletion_reviewed_at', 'deletion_reviewed_by']);
        // Who reviewed each one, in one query for the whole list.
        $reviewers = User::query()->whereIn('id', $submissions->pluck('deletion_reviewed_by')->filter()->unique()->all())->pluck('name', 'id');

        $rows = $submissions->map(function (Submission $submission) use ($reviewers): array {
            $key = $this->sources->keyOf($submission->source_id);
            $assessment = $submission->assessment;
            /** @var QuarantinedRecord|null $record */
            $record = $submission->quarantinedRecords->first();
            $round = $assessment !== null ? $assessment->round : $record?->round;

            return [
                'submission_id' => $submission->id,
                'source' => $key === null ? null : ['key' => $key, 'name' => $this->sources->name($key)],
                'instance_id' => $submission->instance_id,
                'deleted_at' => $submission->deleted_at_source?->toIso8601String() ?? '',
                'facility' => $assessment === null ? null : ['id' => $assessment->facility->id, 'code' => $assessment->facility->code, 'name' => $assessment->facility->name],
                'round' => $round === null ? null : ['id' => $round->id, 'year' => $round->year, 'quarter' => $round->quarter],
                'assessment' => $assessment === null ? null : ['id' => $assessment->id, 'status' => $assessment->status],
                'quarantined_record_id' => $record?->id,
                'source_url' => $key === null ? null : $this->links->submission($key, is_string($submission->external_id) ? $submission->external_id : null),
                'reviewed' => $submission->deletion_reviewed_at === null ? null : [
                    'at' => $submission->deletion_reviewed_at->toIso8601String(),
                    'by' => $submission->deletion_reviewed_by === null ? null : (string) ($reviewers[$submission->deletion_reviewed_by] ?? ''),
                ],
            ];
        });

        return ['rows' => array_values($rows->all()), 'total' => $total];
    }
}
