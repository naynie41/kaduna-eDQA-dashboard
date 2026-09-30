<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Four demo rounds (local and staging only), matching the prototype's quarters: Q3 2025 to
 * Q1 2026 closed, Q2 2026 open. Each window is its calendar quarter, so month slots 1–3 are
 * the quarter's months (§3). The real historical windows are open question Q-10.
 * Idempotent: upsert by (year, quarter).
 */
final class RoundSeeder extends Seeder
{
    /** @var list<array{0: int, 1: int, 2: string}> year, quarter, status */
    private const ROUNDS = [
        [2025, 3, 'closed'],
        [2025, 4, 'closed'],
        [2026, 1, 'closed'],
        [2026, 2, 'open'],
    ];

    public function run(): void
    {
        $now = now();
        $rows = [];

        foreach (self::ROUNDS as [$year, $quarter, $status]) {
            $start = CarbonImmutable::create($year, 3 * $quarter - 2, 1);
            $end = $start->addMonths(3)->subDay();

            $rows[] = [
                'year' => $year,
                'quarter' => $quarter,
                'window_start' => $start->toDateString(),
                'window_end' => $end->toDateString(),
                'status' => $status,
                // Closed about three weeks after the window, once late submissions were in.
                'closed_at' => $status === 'closed' ? $end->addDays(21)->setTime(17, 0) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('rounds')->upsert($rows, ['year', 'quarter'], ['window_start', 'window_end', 'status', 'closed_at', 'updated_at']);
    }
}
