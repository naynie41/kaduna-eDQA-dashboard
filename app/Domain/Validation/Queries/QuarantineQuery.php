<?php

declare(strict_types=1);

namespace App\Domain\Validation\Queries;

use App\Domain\Facility\Models\Lga;
use App\Domain\Validation\Models\QuarantinedRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Data issues listing (ARCHITECTURE.md §6): quarantined records, flat or grouped by rule,
 * filtered by status, rule, round and LGA. Admin-only; Reporting never reads quarantine.
 *
 * The LGA filter takes an LGA code and matches the submitted lga_ref by that code or the LGA's
 * name, ignoring case and surrounding spaces (the ref is raw, so it may be either).
 */
final class QuarantineQuery
{
    public const PER_PAGE = 50;

    /**
     * @param  array{status?: ?string, rule?: ?string, round?: int|string|null, lga?: ?string}  $filters
     * @return LengthAwarePaginator<int, QuarantinedRecord>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->filtered($filters)->orderByDesc('id')->paginate(self::PER_PAGE);
    }

    /** Records still waiting for an administrator, counted now. */
    public function openCount(): int
    {
        return QuarantinedRecord::query()->where('status', 'open')->count();
    }

    /** The cache key of openCountCached(). */
    public const OPEN_COUNT_KEY = 'edqa:quarantine:open-count';

    /**
     * The open count for every page (the shell's "N in quarantine", the Data issues badge), cached:
     * FlushOpenQuarantineCount forgets it when a record is quarantined or resolved, and it lapses
     * after 60 s in any case, so a change made another way shows within a minute.
     */
    public function openCountCached(): int
    {
        return (int) Cache::remember(self::OPEN_COUNT_KEY, 60, fn (): int => $this->openCount());
    }

    public static function forgetOpenCount(): void
    {
        Cache::forget(self::OPEN_COUNT_KEY);
    }

    /**
     * Open records per round, for the given rounds (GET /api/odk/status); a round with none is 0.
     *
     * @param  list<int>  $roundIds
     * @return array<int, int> round ID => open records
     */
    public function openCountsByRound(array $roundIds): array
    {
        $counts = array_fill_keys($roundIds, 0);
        if ($roundIds === []) {
            return $counts;
        }
        $rows = QuarantinedRecord::query()->where('status', 'open')->whereIn('round_id', $roundIds)
            ->groupBy('round_id')->selectRaw('round_id, COUNT(*) AS open')->get();
        foreach ($rows as $row) {
            $counts[(int) $row->getAttribute('round_id')] = (int) $row->getAttribute('open');
        }

        return $counts;
    }

    /**
     * Records per hard-failure code, most frequent first. A record failing two rules counts in
     * both groups.
     *
     * @param  array{status?: ?string, rule?: ?string, round?: int|string|null, lga?: ?string}  $filters
     * @return list<array{code: string, count: int}>
     */
    public function countsByRule(array $filters): array
    {
        $ids = $this->filtered($filters)->select('id');

        $rows = DB::table('quarantined_records as q')
            ->crossJoin(DB::raw('jsonb_array_elements(q.failures) AS f(failure)'))
            ->whereIn('q.id', $ids)
            ->whereRaw("f.failure->>'severity' = 'hard'")
            ->selectRaw("f.failure->>'code' AS code, count(DISTINCT q.id) AS count")
            ->groupBy('code')
            ->orderByDesc('count')
            ->orderBy('code')
            ->get();

        return array_values($rows->map(fn (object $row): array => ['code' => (string) $row->code, 'count' => (int) $row->count])->all());
    }

    /** The Data issues page's states (D-57): open, or how a record was resolved. */
    public const STATES = ['open', 'resolved', 'rejected', 'superseded'];

    public const ADMIN_PER_PAGE = 25;

    /**
     * The Data issues page's records (D-57): newest first, 25 a page, with each record's
     * submission (received at, form) and round, for the filters below plus `state`.
     *
     * @param  array{state?: ?string, rule?: ?string, round?: int|string|null, lga?: ?string}  $filters
     * @return Paginator<int, QuarantinedRecord>
     */
    public function adminPage(array $filters): Paginator
    {
        return $this->filtered($filters)
            ->with(['submission:id,instance_id,form_ref,received_at,status', 'round:id,year,quarter'])
            ->orderByDesc('id')
            ->paginate(self::ADMIN_PER_PAGE)
            ->withQueryString();
    }

    /**
     * The master list's name for each facility reference on a page, matched as FACILITY_UNKNOWN
     * matches them (trimmed, any case). One query.
     *
     * @param  list<?string>  $refs
     * @return array<string, string> lower-cased ref => facility name
     */
    public function facilityNames(array $refs): array
    {
        $keys = array_values(array_unique(array_filter(array_map(fn (?string $ref): string => mb_strtolower(trim((string) $ref)), $refs), fn (string $key): bool => $key !== '')));
        if ($keys === []) {
            return [];
        }

        $names = [];
        foreach (DB::table('facilities')->whereIn(DB::raw('lower(code)'), $keys)->get(['code', 'name']) as $row) {
            $names[mb_strtolower((string) $row->code)] = (string) $row->name;
        }

        return $names;
    }

    /**
     * @param  array{status?: ?string, state?: ?string, rule?: ?string, round?: int|string|null, lga?: ?string}  $filters
     * @return Builder<QuarantinedRecord>
     */
    private function filtered(array $filters): Builder
    {
        $query = QuarantinedRecord::query();

        match ($filters['state'] ?? null) {
            'open' => $query->where('status', 'open'),
            'resolved' => $query->where('status', 'resolved')->where('resolution', 'reprocessed'),
            'rejected' => $query->where('resolution', 'rejected'),
            'superseded' => $query->where('resolution', 'superseded'),
            default => null,
        };

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
        if (($filters['rule'] ?? null) !== null) {
            // Uses the GIN index on failures.
            $query->whereRaw('failures @> ?::jsonb', [json_encode([['code' => $filters['rule']]], JSON_THROW_ON_ERROR)]);
        }
        if (($filters['round'] ?? null) !== null) {
            $query->where('round_id', (int) $filters['round']);
        }
        if (($filters['lga'] ?? null) !== null) {
            $lga = Lga::query()->where('code', $filters['lga'])->first(['code', 'name']);
            $names = $lga === null ? [] : [mb_strtolower($lga->code), mb_strtolower($lga->name)];
            $query->whereIn(DB::raw('lower(btrim(lga_ref))'), $names);
        }

        return $query;
    }
}
