<?php

declare(strict_types=1);

namespace App\Domain\Round\Actions;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Round\DTOs\RoundWindowResult;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Exceptions\RoundActionRefused;
use App\Domain\Round\Models\Round;
use App\Domain\Round\Support\RoundWindowCheck;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Edit window" (ARCHITECTURE.md §3): an open round's window moves under the same checks as a new
 * one, and never so that an accepted visit (accepted or flagged assessment, by its local visit
 * date, D-30) would fall outside it. A closed round is refused: reopen it first. Audited old/new.
 */
final class UpdateRoundWindow
{
    public function __construct(
        private readonly RoundWindowCheck $windows,
    ) {}

    public function execute(Round $round, CarbonImmutable $start, CarbonImmutable $end, User $actor): RoundWindowResult
    {
        return DB::transaction(function () use ($round, $start, $end, $actor): RoundWindowResult {
            $round = Round::query()->lockForUpdate()->findOrFail($round->id);
            if ($round->status === RoundStatus::Closed) {
                throw RoundActionRefused::roundClosed();
            }

            $warnings = $this->windows->check($round->year, $round->quarter, $start, $end, ignoreRoundId: $round->id);

            // The session runs in the app's zone (D-30), so started_at::date is the local visit date.
            $outside = Assessment::query()
                ->where('round_id', $round->id)
                ->whereIn('status', ['accepted', 'flagged'])
                ->where(fn ($q) => $q->whereRaw('started_at::date < ?::date', [$start->toDateString()])
                    ->orWhereRaw('started_at::date > ?::date', [$end->toDateString()]))
                ->pluck('id');
            if ($outside->isNotEmpty()) {
                throw RoundActionRefused::assessmentsOutsideWindow($outside->count());
            }

            $old = ['window_start' => $round->window_start->toDateString(), 'window_end' => $round->window_end->toDateString()];
            $new = ['window_start' => $start->toDateString(), 'window_end' => $end->toDateString()];
            activity()->withoutLogs(fn () => $round->update($new));

            AuditLog::record($round, $actor, 'window_updated',
                old: $old,
                new: $new,
                extra: ['warnings' => array_column($warnings, 'code')],
            );

            return new RoundWindowResult($round->refresh(), $warnings);
        });
    }
}
