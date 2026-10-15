<?php

declare(strict_types=1);

namespace App\Domain\Round\Actions;

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
 * "Create round" (ARCHITECTURE.md §3): one per year and quarter, open, with a window that passes
 * RoundWindowCheck. No version is pinned until it closes (D-32). One audit entry, with any
 * warning the window raised.
 */
final class CreateRound
{
    public function __construct(
        private readonly RoundWindowCheck $windows,
    ) {}

    public function execute(int $year, int $quarter, CarbonImmutable $start, CarbonImmutable $end, User $actor): RoundWindowResult
    {
        return DB::transaction(function () use ($year, $quarter, $start, $end, $actor): RoundWindowResult {
            if (Round::query()->where(['year' => $year, 'quarter' => $quarter])->exists()) {
                throw RoundActionRefused::roundExists(RoundWindowCheck::label($year, $quarter));
            }
            $warnings = $this->windows->check($year, $quarter, $start, $end);

            $attributes = [
                'year' => $year,
                'quarter' => $quarter,
                'window_start' => $start->toDateString(),
                'window_end' => $end->toDateString(),
                'status' => RoundStatus::Open->value,
            ];
            $round = activity()->withoutLogs(fn (): Round => Round::query()->create($attributes));

            AuditLog::record($round, $actor, 'created',
                old: [],
                new: $attributes,
                extra: ['warnings' => array_column($warnings, 'code')],
            );

            return new RoundWindowResult($round, $warnings);
        });
    }
}
