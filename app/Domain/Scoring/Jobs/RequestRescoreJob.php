<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Jobs;

use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Actions\RescoreRound;
use App\Domain\Scoring\Enums\RescoreTrigger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An automatic trigger's request to rescore a round (publish, quarantine resolved, correction).
 * Debounced: unique per round and delayed edqa.rescore.debounce_seconds, so resolving fifty
 * records in one go rescores the round once. The lock is released when the job starts, so a
 * trigger arriving during the rescore queues the next one and is never lost. The first trigger
 * names the run. A round closed in the meantime is skipped, not refused (D-32).
 */
final class RequestRescoreJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public function __construct(
        public readonly int $roundId,
        public readonly RescoreTrigger $trigger,
    ) {
        $this->uniqueFor = max(1, (int) config('edqa.rescore.debounce_seconds'));
        $this->onQueue(RescoreRound::QUEUE);
        $this->delay($this->uniqueFor);
    }

    public static function debounced(int $roundId, RescoreTrigger $trigger): void
    {
        self::dispatch($roundId, $trigger);
    }

    public function uniqueId(): string
    {
        return "round:{$this->roundId}";
    }

    public function handle(RescoreRound $rescore): void
    {
        $round = Round::query()->find($this->roundId);
        if ($round === null || $round->status === RoundStatus::Closed) {
            Log::channel('edqa')->info('rescore.skipped_closed_round', ['round_id' => $this->roundId, 'trigger' => $this->trigger->value]);

            return;
        }

        $rescore->start($round, $this->trigger);
    }

    public function failed(?Throwable $e): void
    {
        Log::channel('edqa')->error('rescore.request_failed', [
            'round_id' => $this->roundId,
            'trigger' => $this->trigger->value,
            'error' => $e?->getMessage(),
        ]);
    }
}
