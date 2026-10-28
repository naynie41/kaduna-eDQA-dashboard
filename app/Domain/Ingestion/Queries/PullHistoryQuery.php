<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Models\PullRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The ODK pull page's history (ARCHITECTURE.md §9.3): every run, newest first, 25 a page,
 * filtered by trigger and by outcome ("running" for one not finished). Who triggered it is the
 * administrator's name, or null for the schedule, the webhook and the console. The duration is
 * split here into minutes and seconds, so the page only lays it out.
 */
final class PullHistoryQuery
{
    public const PER_PAGE = 25;

    public const OUTCOMES = ['succeeded', 'failed', 'skipped_not_configured', 'running'];

    /**
     * @param  array{trigger?: ?string, outcome?: ?string}  $filters
     * @return LengthAwarePaginator<int, array{id: int, started_at: string, trigger: string, triggered_by: ?string, fetched: int, parsed: int, accepted: int, flagged: int, quarantined: int, duplicates: int, duration: ?array{minutes: int, seconds: int}, outcome: string, error: ?string}>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $trigger = PullTrigger::tryFrom((string) ($filters['trigger'] ?? ''));
        $outcome = $filters['outcome'] ?? null;

        $query = PullRun::query()->with('triggeredBy:id,name')->orderByDesc('started_at')->orderByDesc('id');
        if ($trigger !== null) {
            $query->where('trigger_type', $trigger);
        }
        if ($outcome === 'running') {
            $query->whereNull('outcome');
        } elseif (is_string($outcome) && PullOutcome::tryFrom($outcome) !== null) {
            $query->where('outcome', $outcome);
        }

        /** @var LengthAwarePaginator<int, array{id: int, started_at: string, trigger: string, triggered_by: ?string, fetched: int, parsed: int, accepted: int, flagged: int, quarantined: int, duplicates: int, duration: ?array{minutes: int, seconds: int}, outcome: string, error: ?string}> */
        return $query->paginate(self::PER_PAGE)->withQueryString()->through(function (PullRun $run): array {
            $seconds = $run->finished_at === null ? null : max(0, (int) $run->started_at->diffInSeconds($run->finished_at));

            return [
                'id' => $run->id,
                'started_at' => $run->started_at->toIso8601String(),
                'trigger' => $run->trigger_type->value,
                'triggered_by' => $run->triggeredBy?->name,
                'fetched' => $run->fetched,
                'parsed' => $run->parsed,
                'accepted' => $run->accepted,
                'flagged' => $run->flagged,
                'quarantined' => $run->quarantined,
                'duplicates' => $run->duplicates,
                'duration' => $seconds === null ? null : ['minutes' => intdiv($seconds, 60), 'seconds' => $seconds % 60],
                'outcome' => $run->outcome->value ?? 'running',
                'error' => $run->error,
            ];
        });
    }
}
