<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\SourceLinks;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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

    /**
     * The Data issues page's Deleted at source tab (D-62, Phase 5 step 2): each assessment whose
     * submission its source no longer returns, newest deletion first, 50 a page. Open while the
     * deletion is unreviewed; any other status lists the reviewed ones (kept or withdrawn).
     *
     * @param  array{state: string, source_id: ?int, round: ?int, lga: ?string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function assessedPage(array $filters): LengthAwarePaginator
    {
        $page = DB::table('submissions as s')
            ->join('assessments as a', 'a.submission_id', '=', 's.id')
            ->join('facilities as f', 'f.id', '=', 'a.facility_id')
            ->join('lgas as l', 'l.id', '=', 'f.lga_id')
            ->join('rounds as r', 'r.id', '=', 'a.round_id')
            ->leftJoin('users as u', 'u.id', '=', 's.deletion_reviewed_by')
            ->whereNotNull('s.deleted_at_source')
            ->when($filters['state'] === 'open', fn ($q) => $q->whereNull('s.deletion_reviewed_at'), fn ($q) => $q->whereNotNull('s.deletion_reviewed_at'))
            ->when($filters['source_id'] !== null, fn ($q) => $q->where('s.source_id', $filters['source_id']))
            ->when($filters['round'] !== null, fn ($q) => $q->where('a.round_id', $filters['round']))
            ->when($filters['lga'] !== null, fn ($q) => $q->where('l.code', $filters['lga']))
            ->orderByDesc('s.deleted_at_source')->orderByDesc('s.id')
            ->select([
                's.id as submission_id', 's.source_id', 's.instance_id', 's.external_id', 's.deleted_at_source', 's.deletion_reviewed_at', 'u.name as reviewer',
                'a.id as assessment_id', 'a.status as assessment_status', 'f.id as facility_id', 'f.code as facility_code', 'f.name as facility_name',
                'l.name as lga', 'r.id as round_id', 'r.year', 'r.quarter', 'r.status as round_status',
            ])
            ->paginate(self::LIMIT);

        /** @var LengthAwarePaginator<int, array<string, mixed>> $rows */
        $rows = $page->through(fn (object $row): array => $this->assessedRow($row));

        return $rows;
    }

    /** @return array<string, mixed> one row of the Deleted at source tab */
    private function assessedRow(object $row): array
    {
        $key = $this->sources->keyOf((int) $row->source_id);

        return [
            'submission_id' => (int) $row->submission_id,
            'source' => $key === null ? null : ['key' => $key, 'name' => $this->sources->name($key)],
            'instance_id' => (string) $row->instance_id,
            'source_url' => $key === null ? null : $this->links->submission($key, is_string($row->external_id) ? $row->external_id : null),
            'deleted_at' => CarbonImmutable::parse((string) $row->deleted_at_source)->toIso8601String(),
            'assessment' => ['id' => (int) $row->assessment_id, 'status' => (string) $row->assessment_status],
            'facility' => ['id' => (int) $row->facility_id, 'code' => (string) $row->facility_code, 'name' => (string) $row->facility_name],
            'lga' => (string) $row->lga,
            'round' => ['id' => (int) $row->round_id, 'year' => (int) $row->year, 'quarter' => (int) $row->quarter, 'open' => $row->round_status === 'open'],
            'reviewed' => $row->deletion_reviewed_at === null ? null : [
                'at' => CarbonImmutable::parse((string) $row->deletion_reviewed_at)->toIso8601String(),
                'by' => is_string($row->reviewer) ? $row->reviewer : null,
            ],
        ];
    }
}
