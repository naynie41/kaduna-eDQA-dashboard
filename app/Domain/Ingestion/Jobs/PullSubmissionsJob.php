<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Jobs;

use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Events\PullFailed;
use App\Domain\Ingestion\Models\OdkFormSync;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Domain\Ingestion\Odk\OdkConfig;
use App\Domain\Ingestion\Odk\PullLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Pulls new and edited submissions from ODK Central (ARCHITECTURE.md §5.3): every ten minutes
 * from the schedule, on "Pull now" and from the webhook. One pull at a time under PullLock: a
 * second dispatch waits for the running one and then pulls (finding little or nothing new), never
 * the same pages twice at once. Waiting too long puts it back on the queue.
 *
 * Every attempt is its own odk_pull_runs row; the last failure also lands on the form's sync row
 * and raises PullFailed (the daily digest and the ODK pull page read both).
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
        public readonly PullTrigger $trigger = PullTrigger::Scheduled,
        public readonly ?int $userId = null,
        public readonly ?int $runId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(PullLock $lock, PullSubmissions $pull): void
    {
        try {
            $lock->run(fn (): OdkPullRun => $pull->execute($this->trigger, $this->userId, $this->runId), (int) config('edqa.odk.pull.lock_wait_seconds', 600));
        } catch (LockTimeoutException) {
            $this->release(60);
        }
    }

    public function failed(?Throwable $e): void
    {
        // A run created when the pull was asked for, never reached (the lock stayed busy): close it.
        if ($this->runId !== null) {
            OdkPullRun::query()->whereKey($this->runId)->whereNull('finished_at')->update([
                'outcome' => PullOutcome::Failed, 'finished_at' => now(),
                'error' => $e === null ? 'Unknown failure' : PullSubmissions::describe($e),
            ]);
        }
        self::recordFailure($e);
    }

    /** The final failure of a pull: on the form's sync row, and PullFailed. Also used by edqa:pull. */
    public static function recordFailure(?Throwable $e): void
    {
        $message = $e === null ? 'Unknown failure' : PullSubmissions::describe($e);
        $run = OdkPullRun::query()->where('outcome', PullOutcome::Failed)->latest('id')->first();

        $config = OdkConfig::fromConfig();
        if ($config->isConfigured()) {
            OdkFormSync::query()
                ->where('project_id', (int) $config->projectId)
                ->where('form_id', (string) $config->formId)
                ->update(['last_error' => $message, 'updated_at' => now()]);
        }

        PullFailed::dispatch($run?->id, $message);
    }
}
