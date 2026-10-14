<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\RoundCache;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes round_aggregates through refresh_round_aggregates() (the only path: SECURITY DEFINER,
 * so the runtime role needs no ownership of the view), then flushes the round's cached answers so
 * the next read sees the new rows.
 */
final class RefreshRoundAggregates
{
    public function __construct(
        private readonly RoundCache $cache,
    ) {}

    public function execute(int $roundId): void
    {
        DB::select('SELECT refresh_round_aggregates()');

        $this->cache->flushRound($roundId);
    }
}
