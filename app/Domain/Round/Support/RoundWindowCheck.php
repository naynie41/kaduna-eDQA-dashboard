<?php

declare(strict_types=1);

namespace App\Domain\Round\Support;

use App\Domain\Round\Exceptions\RoundActionRefused;
use App\Domain\Round\Models\Round;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;

/**
 * The window rules for creating a round or moving its window (ARCHITECTURE.md §3):
 *
 * - it ends on or after the day it starts;
 * - it starts inside its quarter and ends by the quarter's last day plus edqa.rounds.window_grace_days
 *   (late visits): ending after the quarter is allowed, with a warning;
 * - it shares no day with another round's window (ROUND_WINDOW would refuse to pick one).
 *
 * Call inside the action's transaction: it locks the rounds table against a concurrent create or
 * move, so two overlapping windows can never both pass.
 */
final class RoundWindowCheck
{
    public function __construct(
        #[Config('edqa.rounds.window_grace_days')] private readonly int $graceDays,
    ) {}

    /** @return list<array{code: string, message: string}> warnings */
    public function check(int $year, int $quarter, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreRoundId = null): array
    {
        $start = $start->startOfDay();
        $end = $end->startOfDay();
        if ($end->lt($start)) {
            throw RoundActionRefused::windowOrder();
        }

        $quarterStart = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1);
        $quarterEnd = $quarterStart->addMonths(3)->subDay();
        $latest = $quarterEnd->addDays($this->graceDays);
        if ($start->lt($quarterStart) || $start->gt($quarterEnd) || $end->gt($latest)) {
            throw RoundActionRefused::windowOutsideQuarter(self::label($year, $quarter), $quarterStart->toDateString(), $quarterEnd->toDateString(), $latest->toDateString(), $this->graceDays);
        }

        DB::statement('LOCK TABLE rounds IN SHARE ROW EXCLUSIVE MODE');
        $overlapping = Round::query()
            ->when($ignoreRoundId !== null, fn ($q) => $q->whereKeyNot($ignoreRoundId))
            ->whereDate('window_start', '<=', $end->toDateString())
            ->whereDate('window_end', '>=', $start->toDateString())
            ->orderBy('window_start')
            ->first();
        if ($overlapping !== null) {
            throw RoundActionRefused::windowOverlap(self::label($overlapping->year, $overlapping->quarter));
        }

        if ($end->gt($quarterEnd)) {
            $days = (int) $quarterEnd->diffInDays($end);

            return [['code' => 'window_past_quarter_end', 'message' => (string) __('rounds.warnings.window_past_quarter_end', ['days' => (string) $days])]];
        }

        return [];
    }

    public static function label(int $year, int $quarter): string
    {
        return (string) __('reporting.round_label', ['quarter' => (string) $quarter, 'year' => (string) $year]);
    }
}
