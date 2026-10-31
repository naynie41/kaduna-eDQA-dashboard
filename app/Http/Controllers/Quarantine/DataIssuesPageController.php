<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quarantine;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Lga;
use App\Domain\Ingestion\Exceptions\SourceRefused;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Reporting\Aggregators\SoftFlagAggregator;
use App\Domain\Reporting\Queries\FlaggedAssessmentsQuery;
use App\Domain\Round\Models\Round;
use App\Domain\Validation\Actions\CorrectAndReprocess;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Domain\Validation\Queries\QuarantineQuery;
use App\Domain\Validation\RuleCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DataIssuesRequest;
use App\Http\Resources\Admin\FlaggedAssessmentResource;
use App\Http\Resources\Quarantine\DataIssueRowResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Data issues page (ARCHITECTURE.md §6, §9.3, D-57). The rule summary (every code with its
 * severity, name, why it exists and its open count), the filters in the URL, and one page of
 * rows: quarantined records on the Hard tab, flagged accepted assessments on the Soft tab. The
 * page acts through the 2A.8 API (resolve, bulk resolve, override) and reloads only what changed.
 * The source filter is a configured key, turned here into its row id for both tabs; every
 * configured source is offered in config order.
 */
final class DataIssuesPageController extends Controller
{
    public function __invoke(DataIssuesRequest $request, RuleCatalogue $catalogue, QuarantineQuery $quarantine, SoftFlagAggregator $soft, FlaggedAssessmentsQuery $flagged, SourceRegistry $sources): Response
    {
        $filters = $request->filters();
        $query = [...$filters, 'source_id' => self::sourceId($sources, $filters['source'])];
        $softCodes = $catalogue->codes(Severity::Soft);

        return Inertia::render('admin/data-issues', [
            'issueFilters' => $filters,
            'rules' => function () use ($catalogue, $quarantine, $soft, $softCodes): array {
                $hard = array_column($quarantine->countsByRule(['status' => 'open']), 'count', 'code');
                $softCounts = $soft->openCounts($softCodes);

                return array_map(fn (array $rule): array => [
                    'code' => $rule['code'],
                    'severity' => $rule['severity']->value,
                    'name' => (string) __("validation_rules.{$rule['code']}.name"),
                    'why' => (string) __("validation_rules.{$rule['code']}.why"),
                    'open' => $rule['severity'] === Severity::Soft ? ($softCounts[$rule['code']] ?? 0) : ($hard[$rule['code']] ?? 0),
                ], $catalogue->all());
            },
            'records' => fn () => $filters['tab'] === 'soft'
                ? $flagged->page($softCodes, $query)->through(fn (Assessment $a): array => (new FlaggedAssessmentResource($a, $softCodes))->resolve($request))
                : self::hardPage($quarantine, $query, $request),
            'lgas' => fn (): array => Lga::query()->orderBy('name')->get(['code', 'name'])->map(fn (Lga $l): array => ['code' => $l->code, 'name' => $l->name])->all(),
            'rounds' => fn (): array => Round::query()->orderByDesc('year')->orderByDesc('quarter')->get(['id', 'year', 'quarter', 'status'])
                ->map(fn (Round $r): array => ['id' => $r->id, 'year' => $r->year, 'quarter' => $r->quarter, 'status' => $r->status->value])->all(),
            'sources' => fn (): array => array_map(fn (string $key): array => ['key' => $key, 'name' => $sources->name($key)], $sources->keys()),
            'correctable' => array_keys(CorrectAndReprocess::CORRECTABLE),
        ]);
    }

    /**
     * @param  array{tab: string, state: string, rule: ?string, lga: ?string, round: ?int, source: ?string, source_id: ?int}  $filters
     * @return LengthAwarePaginator<int, array<array-key, mixed>>
     */
    private static function hardPage(QuarantineQuery $quarantine, array $filters, DataIssuesRequest $request): LengthAwarePaginator
    {
        $page = $quarantine->adminPage($filters);
        $names = $quarantine->facilityNames(array_map(fn (QuarantinedRecord $r): ?string => $r->facility_ref, $page->items()));
        $originals = $quarantine->duplicateOriginals(array_values($page->items()));

        return $page->through(fn (QuarantinedRecord $record): array => (new DataIssueRowResource($record, $names, $originals[$record->id] ?? null))->resolve($request));
    }

    /** The filter's source row id; -1 (matches nothing) for a configured source not registered yet. */
    private static function sourceId(SourceRegistry $sources, ?string $key): ?int
    {
        if ($key === null) {
            return null;
        }
        try {
            return $sources->id($key);
        } catch (SourceRefused) {
            return -1;
        }
    }
}
