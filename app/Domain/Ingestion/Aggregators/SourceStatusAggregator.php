<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Aggregators;

use App\Domain\Ingestion\Exceptions\SourceRefused;
use App\Domain\Ingestion\SourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;

/**
 * Each source's sync state (ARCHITECTURE.md §9.1, §9.3), from its own pull_runs. Lives in
 * Ingestion, not Reporting, because it reads the source registry and submissions (hard rule 6).
 *
 * States, in the order checked; the status bar says each in words (ACCESSIBILITY.md §6):
 * - not_configured: a setting is missing, so nothing is pulled (neutral);
 * - disabled: configured, but switched off in env or by an administrator (neutral);
 * - auth_failed: the latest finished pull failed because the source refused the credentials;
 * - failed: the latest finished pull failed for any other reason;
 * - not_connected: configured and on, but no pull has finished yet (neutral);
 * - stale: the last success is older than edqa.status.stale_after_minutes (30);
 * - healthy.
 * A backfill's runs never set the state (hours, once, it says nothing about how fresh the data is),
 * and neither does a run still going or one skipped as not configured. A reconcile sweep does: it
 * talks to the source like a pull.
 */
final class SourceStatusAggregator
{
    /** Worst first, for the status bar; ties keep config order. */
    public const SEVERITY = ['auth_failed', 'failed', 'stale', 'not_connected', 'healthy', 'disabled', 'not_configured'];

    public function __construct(
        private readonly SourceRegistry $sources,
        #[Config('edqa.status.stale_after_minutes')] private readonly int $staleAfter,
    ) {}

    /**
     * Every source in config, worst state first.
     *
     * @return list<array{key: string, name: string, state: string, lastSuccessAt: ?string, minutesSinceSuccess: ?int, sinceSuccess: ?array{value: int, unit: string}, lastFailedAt: ?string, lastError: ?string, lastErrorClass: ?string, lastErrorHttpStatus: ?int, staleAfterMinutes: int}>
     */
    public function all(): array
    {
        $statuses = array_map($this->source(...), $this->sources->keys());
        $rank = array_flip(self::SEVERITY);
        // usort is stable since PHP 8.0, so equal states keep config order.
        usort($statuses, fn (array $a, array $b): int => $rank[$a['state']] <=> $rank[$b['state']]);

        return $statuses;
    }

    /**
     * @return array{key: string, name: string, state: string, lastSuccessAt: ?string, minutesSinceSuccess: ?int, sinceSuccess: ?array{value: int, unit: string}, lastFailedAt: ?string, lastError: ?string, lastErrorClass: ?string, lastErrorHttpStatus: ?int, staleAfterMinutes: int}
     */
    public function source(string $key): array
    {
        $sourceId = $this->sourceId($key);
        $pulls = fn () => DB::table('pull_runs')->where('source_id', $sourceId ?? 0)->where('trigger_type', '!=', 'backfill');
        $latest = $sourceId === null ? null : $pulls()->whereNotNull('finished_at')->whereIn('outcome', ['succeeded', 'failed'])
            ->orderByDesc('finished_at')->orderByDesc('id')->first(['finished_at', 'outcome', 'error']);
        $success = $sourceId === null ? null : $pulls()->where('outcome', 'succeeded')->max('finished_at');
        $lastSuccess = is_string($success) ? CarbonImmutable::parse($success) : null;
        $minutes = $lastSuccess === null ? null : (int) $lastSuccess->diffInMinutes(now());

        $failed = $latest !== null && $latest->outcome === 'failed';
        $error = $failed && is_string($latest->error) ? $latest->error : null;
        // PullSubmissions::describe() writes "Class: message"; the bar names the class.
        $errorClass = $error !== null && preg_match('/^([A-Za-z]+):/', $error, $m) === 1 ? $m[1] : null;

        $state = match (true) {
            $this->sources->problems($key) !== [] => 'not_configured',
            ! in_array($key, $this->sources->enabled(), true) => 'disabled',
            $failed && $errorClass !== null && str_ends_with($errorClass, 'AuthFailed') => 'auth_failed',
            $failed => 'failed',
            $minutes === null => 'not_connected',
            $minutes > $this->staleAfter => 'stale',
            default => 'healthy',
        };
        $showsFailure = in_array($state, ['failed', 'auth_failed'], true);

        return [
            'key' => $key,
            'name' => $this->sources->name($key),
            'state' => $state,
            'lastSuccessAt' => $lastSuccess?->toIso8601String(),
            'minutesSinceSuccess' => $minutes,
            'sinceSuccess' => self::since($minutes),
            'lastFailedAt' => $showsFailure ? CarbonImmutable::parse((string) $latest->finished_at)->toIso8601String() : null,
            'lastError' => $showsFailure ? $error : null,
            'lastErrorClass' => $showsFailure ? $errorClass : null,
            // The Unreachable and UnexpectedResponse errors name the status in their message ("HTTP 503").
            'lastErrorHttpStatus' => $showsFailure && $error !== null && preg_match('/\bHTTP (\d{3})\b/', $error, $h) === 1 ? (int) $h[1] : null,
            'staleAfterMinutes' => $this->staleAfter,
        ];
    }

    /**
     * The source's accepted, flagged and open quarantined assessments in each open round, newest
     * first (GET /api/sources: "open-round counts"). An assessment belongs to the source of the
     * submission it was built from.
     *
     * @return list<array{id: int, year: int, quarter: int, accepted: int, flagged: int, quarantined: int}>
     */
    public function openRounds(string $key): array
    {
        $sourceId = $this->sourceId($key);
        $rounds = DB::table('rounds')->where('status', 'open')->orderByDesc('year')->orderByDesc('quarter')->get(['id', 'year', 'quarter']);
        $assessments = fn (int $roundId, string $status): int => $sourceId === null ? 0 : DB::table('assessments as a')
            ->join('submissions as s', 's.id', '=', 'a.submission_id')
            ->where('s.source_id', $sourceId)->where('a.round_id', $roundId)->where('a.status', $status)
            ->count();

        return array_values($rounds->map(fn (object $round): array => [
            'id' => (int) $round->id, 'year' => (int) $round->year, 'quarter' => (int) $round->quarter,
            'accepted' => $assessments((int) $round->id, 'accepted'),
            'flagged' => $assessments((int) $round->id, 'flagged'),
            'quarantined' => $sourceId === null ? 0 : DB::table('quarantined_records as q')
                ->join('submissions as s', 's.id', '=', 'q.submission_id')
                ->where('s.source_id', $sourceId)->where('q.round_id', (int) $round->id)->where('q.status', 'open')
                ->count(),
        ])->all());
    }

    /** The source's row id, or null while edqa:sources:sync has not created it. */
    private function sourceId(string $key): ?int
    {
        try {
            return $this->sources->id($key);
        } catch (SourceRefused) {
            return null;
        }
    }

    /**
     * Time since the last success in its largest whole unit ("4 minutes", "3 hours", "2 days"):
     * the rounding happens here, never in the browser.
     *
     * @return array{value: int, unit: string}|null
     */
    private static function since(?int $minutes): ?array
    {
        return match (true) {
            $minutes === null => null,
            $minutes < 60 => ['value' => $minutes, 'unit' => 'minutes'],
            $minutes < 48 * 60 => ['value' => intdiv($minutes, 60), 'unit' => 'hours'],
            default => ['value' => intdiv($minutes, 24 * 60), 'unit' => 'days'],
        };
    }
}
