<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quarantine;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Lga;
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
 */
final class DataIssuesPageController extends Controller
{
    public function __invoke(DataIssuesRequest $request, RuleCatalogue $catalogue, QuarantineQuery $quarantine, SoftFlagAggregator $soft, FlaggedAssessmentsQuery $flagged): Response
    {
        $filters = $request->filters();
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
                ? $flagged->page($softCodes, $filters)->through(fn (Assessment $a): array => (new FlaggedAssessmentResource($a, $softCodes))->resolve($request))
                : self::hardPage($quarantine, $filters, $request),
            'lgas' => fn (): array => Lga::query()->orderBy('name')->get(['code', 'name'])->map(fn (Lga $l): array => ['code' => $l->code, 'name' => $l->name])->all(),
            'rounds' => fn (): array => Round::query()->orderByDesc('year')->orderByDesc('quarter')->get(['id', 'year', 'quarter', 'status'])
                ->map(fn (Round $r): array => ['id' => $r->id, 'year' => $r->year, 'quarter' => $r->quarter, 'status' => $r->status->value])->all(),
            'correctable' => array_keys(CorrectAndReprocess::CORRECTABLE),
        ]);
    }

    /**
     * @param  array{tab: string, state: string, rule: ?string, lga: ?string, round: ?int}  $filters
     * @return LengthAwarePaginator<int, array<array-key, mixed>>
     */
    private static function hardPage(QuarantineQuery $quarantine, array $filters, DataIssuesRequest $request): LengthAwarePaginator
    {
        $page = $quarantine->adminPage($filters);
        $names = $quarantine->facilityNames(array_map(fn (QuarantinedRecord $r): ?string => $r->facility_ref, $page->items()));

        return $page->through(fn (QuarantinedRecord $record): array => (new DataIssueRowResource($record, $names))->resolve($request));
    }
}
