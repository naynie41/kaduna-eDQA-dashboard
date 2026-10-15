<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;

/**
 * The shell's status (ARCHITECTURE.md §9.1): the ODK sync state from odk_pull_runs, and how many
 * assessments count. Never the quarantine (hard rule 6): that count comes from Validation.
 *
 * States: not_connected (no pull has ever succeeded or failed: Phase 2B not built yet, or no
 * credentials); failed (the latest finished pull failed); stale (the last success is older than
 * edqa.status.stale_after_minutes); ok. A pull still running does not change the state.
 */
final class ShellStatusAggregator
{
    public function __construct(
        #[Config('edqa.status.stale_after_minutes')] private readonly int $staleAfter,
    ) {}

    /**
     * @return array{state: string, lastSuccessAt: ?string, minutesSinceSuccess: ?int, lastFailedAt: ?string, lastError: ?string, staleAfterMinutes: int}
     */
    public function sync(): array
    {
        $latest = DB::table('odk_pull_runs')->whereNotNull('finished_at')->orderByDesc('finished_at')->orderByDesc('id')->first(['finished_at', 'outcome', 'error']);
        $success = DB::table('odk_pull_runs')->where('outcome', 'succeeded')->max('finished_at');
        $lastSuccess = is_string($success) ? CarbonImmutable::parse($success) : null;
        $minutes = $lastSuccess === null ? null : (int) $lastSuccess->diffInMinutes(now());

        $state = match (true) {
            $latest === null => 'not_connected',
            $latest->outcome === 'failed' => 'failed',
            $minutes === null => 'not_connected',
            $minutes > $this->staleAfter => 'stale',
            default => 'ok',
        };
        $failed = $latest !== null && $latest->outcome === 'failed';

        return [
            'state' => $state,
            'lastSuccessAt' => $lastSuccess?->toIso8601String(),
            'minutesSinceSuccess' => $minutes,
            'lastFailedAt' => $failed ? CarbonImmutable::parse((string) $latest->finished_at)->toIso8601String() : null,
            'lastError' => $failed ? (is_string($latest->error) ? $latest->error : null) : null,
            'staleAfterMinutes' => $this->staleAfter,
        ];
    }

    /** Accepted and flagged assessments, every round: the rail's footer count. */
    public function assessments(): int
    {
        return DB::table('assessments')->whereIn('status', ['accepted', 'flagged'])->count();
    }
}
