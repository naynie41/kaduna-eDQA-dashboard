<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use Illuminate\Support\Facades\DB;

/**
 * The shell's status (ARCHITECTURE.md §9.1, D-54): how many assessments count and the open rounds.
 * Never the quarantine (hard rule 6): that count comes from Validation. Each source's sync state,
 * and the admin pages' stale-ingestion banner, are Ingestion's (SourceStatusAggregator): Reporting
 * may not read the source registry.
 */
final class ShellStatusAggregator
{
    /** Accepted and flagged assessments, every round: the rail's footer count. */
    public function assessments(): int
    {
        return DB::table('assessments')->whereIn('status', ['accepted', 'flagged'])->count();
    }

    /**
     * Each open round, newest first, with its accepted and flagged assessments (GET /api/odk/status).
     *
     * @return list<array{id: int, year: int, quarter: int, accepted: int, flagged: int}>
     */
    public function openRounds(): array
    {
        $rows = DB::table('rounds as r')
            ->leftJoin('assessments as a', 'a.round_id', '=', 'r.id')
            ->where('r.status', 'open')
            ->groupBy('r.id', 'r.year', 'r.quarter')
            ->orderByDesc('r.year')->orderByDesc('r.quarter')
            ->selectRaw("r.id, r.year, r.quarter, COUNT(a.id) FILTER (WHERE a.status = 'accepted') AS accepted, COUNT(a.id) FILTER (WHERE a.status = 'flagged') AS flagged")
            ->get();

        return array_values($rows->map(fn (object $row): array => [
            'id' => (int) $row->id, 'year' => (int) $row->year, 'quarter' => (int) $row->quarter,
            'accepted' => (int) $row->accepted, 'flagged' => (int) $row->flagged,
        ])->all());
    }
}
