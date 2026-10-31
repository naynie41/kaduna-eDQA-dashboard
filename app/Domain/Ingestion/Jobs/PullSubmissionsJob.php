<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Jobs;

use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Events\PullFailed;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\PullLock;
use App\Domain\Ingestion\SourceRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Pulls new and edited submissions from one source (ARCHITECTURE.md §5.3): every ten minutes per
 * configured and enabled source from the schedule, on "Pull now" and from the webhook. One pull
 * per source at a time under that source's PullLock ("pull:{source}"): a second dispatch for the
 * same source waits for the running one and then pulls (finding little or nothing new), never the
 * same pages twice at once; another source's pull runs alongside. Waiting too long puts it back on
 * the queue.
 *
 * Every attempt is its own pull_runs row for its source; the last failure also lands on that
 * source's sync row and raises PullFailed (the daily digest and the admin pages read both).
 */
final class PullSubmissionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /** Matches the lock: a pull never outlives it. The queue's retry_after must be longer (DEPLOY.md). */
    public int $timeout = 900;

    public function __construct(
        public readonly string $source,
        public readonly PullTrigger $trigger = PullTrigger::Scheduled,
        public readonly ?int $userId = null,
        public readonly ?int $runId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(PullLock $lock, PullSubmissions $pull): void
    {
        try {
            $lock->run($this->source, fn (): PullRun => $pull->execute($this->source, $this->trigger, $this->userId, $this->runId), PullLock::waitSeconds());
        } catch (LockTimeoutException) {
            $this->release(60);
        }
    }

    public function failed(?Throwable $e): void
    {
        // A run created when the pull was asked for, never reached (the lock stayed busy): close it.
        if ($this->runId !== null) {
            PullRun::query()->whereKey($this->runId)->whereNull('finished_at')->update([
                'outcome' => PullOutcome::Failed, 'finished_at' => now(),
                'error' => $e === null ? 'Unknown failure' : PullSubmissions::describe($e),
            ]);
        }
        self::recordFailure($this->source, $e);
    }

    /** The final failure of a pull: on the source's sync row, and PullFailed. Also used by edqa:pull. */
    public static function recordFailure(string $source, ?Throwable $e): void
    {
        $message = $e === null ? 'Unknown failure' : PullSubmissions::describe($e);
        $sources = app(SourceRegistry::class);
        $sourceId = $sources->id($source);
        $run = PullRun::query()->where('source_id', $sourceId)->where('outcome', PullOutcome::Failed)->latest('id')->first();

        if ($sources->problems($source) === []) {
            SourceSync::query()
                ->where('source_id', $sourceId)
                ->where('form_ref', $sources->formRef($source))
                ->update(['last_error' => $message, 'updated_at' => now()]);
        }

        PullFailed::dispatch($run?->id, $message, $source);
    }
}
