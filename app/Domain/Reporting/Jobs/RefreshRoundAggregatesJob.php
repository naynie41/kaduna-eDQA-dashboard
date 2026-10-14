<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Jobs;

use App\Domain\Reporting\Actions\RefreshRoundAggregates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Debounced refresh of round_aggregates (ARCHITECTURE.md §4). A burst of scores in one round
 * queues one job: it is unique per round for 60 s and runs 60 s after the first dispatch. The lock
 * is released when the job starts, so a score landing during the refresh queues the next one and
 * is never lost. Dispatched by RefreshAggregatesAfterScore and directly by rescore and round close.
 */
final class RefreshRoundAggregatesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const DEBOUNCE_SECONDS = 60;

    public int $uniqueFor = self::DEBOUNCE_SECONDS;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 300;

    public function __construct(
        public readonly int $roundId,
    ) {
        $this->delay(self::DEBOUNCE_SECONDS);
    }

    public static function debounced(int $roundId): void
    {
        self::dispatch($roundId);
    }

    public function uniqueId(): string
    {
        return "round:{$this->roundId}";
    }

    public function handle(RefreshRoundAggregates $refresh): void
    {
        $refresh->execute($this->roundId);
    }

    public function failed(?Throwable $e): void
    {
        Log::channel('edqa')->error('round_aggregates.refresh_failed', [
            'round_id' => $this->roundId,
            'error' => $e?->getMessage(),
        ]);
    }
}
