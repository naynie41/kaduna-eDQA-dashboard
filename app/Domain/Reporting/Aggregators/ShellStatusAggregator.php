<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Ingestion\Odk\OdkConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The shell's status (ARCHITECTURE.md §9.1, D-54): how many assessments count, the open rounds and
 * the stale-ingestion banner. Never the quarantine (hard rule 6): that count comes from Validation.
 * Each source's sync state is Ingestion's (SourceStatusAggregator): Reporting may not read the
 * source registry.
 */
final class ShellStatusAggregator
{
    /**
     * The admin pages' banner (SECURITY.md §12, D-55): ODK is configured, a round is open, and no
     * pull has succeeded for edqa.status.banner_after_hours (24), or ever. Not configured is its own
     * state, said by the status bar; a backfill's runs do not count.
     *
     * @return array{stale: bool, hours: int, lastSuccessAt: ?string, round: ?array{year: int, quarter: int}}
     */
    public function staleIngestion(): array
    {
        $hours = (int) config('edqa.status.banner_after_hours', 24);
        $round = DB::table('rounds')->where('status', 'open')->orderByDesc('year')->orderByDesc('quarter')->first(['year', 'quarter']);
        $success = DB::table('pull_runs')->where('trigger_type', '!=', 'backfill')->where('outcome', 'succeeded')->max('finished_at');
        $lastSuccess = is_string($success) ? CarbonImmutable::parse($success) : null;
        $stale = $round !== null && OdkConfig::fromConfig()->isConfigured()
            && ($lastSuccess === null || $lastSuccess->lessThan(now()->subHours($hours)));

        return [
            'stale' => $stale,
            'hours' => $hours,
            'lastSuccessAt' => $lastSuccess?->toIso8601String(),
            'round' => $round === null ? null : ['year' => (int) $round->year, 'quarter' => (int) $round->quarter],
        ];
    }

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
