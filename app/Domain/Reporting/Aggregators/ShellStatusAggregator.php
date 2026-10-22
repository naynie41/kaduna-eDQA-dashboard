<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Ingestion\Odk\OdkConfig;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;

/**
 * The shell's status (ARCHITECTURE.md §9.1, D-54): the ODK sync state from odk_pull_runs, and how
 * many assessments count. Never the quarantine (hard rule 6): that count comes from Validation.
 *
 * States, each said in words by the sync status bar:
 * - not_configured: ODK Central's settings are missing, so nothing is pulled (neutral);
 * - not_connected: configured, but no pull has finished yet (neutral);
 * - failed: the latest finished pull failed: when, the error's class, and the last success;
 * - stale: the last success is older than edqa.status.stale_after_minutes (30);
 * - ok.
 * Only the incremental pull counts: a backfill's runs never set the state. A pull still running
 * does not change it.
 */
final class ShellStatusAggregator
{
    public function __construct(
        #[Config('edqa.status.stale_after_minutes')] private readonly int $staleAfter,
    ) {}

    /**
     * @return array{state: string, lastSuccessAt: ?string, minutesSinceSuccess: ?int, lastFailedAt: ?string, lastError: ?string, lastErrorClass: ?string, staleAfterMinutes: int}
     */
    public function sync(): array
    {
        // The incremental pull only: a backfill (hours, once) says nothing about how fresh the data is.
        $pulls = fn () => DB::table('odk_pull_runs')->where('trigger_type', '!=', 'backfill');
        $latest = $pulls()->whereNotNull('finished_at')->whereIn('outcome', ['succeeded', 'failed'])->orderByDesc('finished_at')->orderByDesc('id')->first(['finished_at', 'outcome', 'error']);
        $success = $pulls()->where('outcome', 'succeeded')->max('finished_at');
        $lastSuccess = is_string($success) ? CarbonImmutable::parse($success) : null;
        $minutes = $lastSuccess === null ? null : (int) $lastSuccess->diffInMinutes(now());

        $state = match (true) {
            ! OdkConfig::fromConfig()->isConfigured() => 'not_configured',
            $latest === null => 'not_connected',
            $latest->outcome === 'failed' => 'failed',
            $minutes === null => 'not_connected',
            $minutes > $this->staleAfter => 'stale',
            default => 'ok',
        };
        $failed = $state === 'failed';
        $error = $failed && is_string($latest->error) ? $latest->error : null;

        return [
            'state' => $state,
            'lastSuccessAt' => $lastSuccess?->toIso8601String(),
            'minutesSinceSuccess' => $minutes,
            'lastFailedAt' => $failed ? CarbonImmutable::parse((string) $latest->finished_at)->toIso8601String() : null,
            'lastError' => $error,
            // PullSubmissions::describe() writes "Class: message"; the bar names the class.
            'lastErrorClass' => $error === null ? null : (preg_match('/^([A-Za-z]+):/', $error, $m) === 1 ? $m[1] : null),
            'staleAfterMinutes' => $this->staleAfter,
        ];
    }

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
        $success = DB::table('odk_pull_runs')->where('trigger_type', '!=', 'backfill')->where('outcome', 'succeeded')->max('finished_at');
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
