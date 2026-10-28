<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Actions\DryRunPull;
use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\OdkException;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Odk\PullLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

/**
 * One ODK pull, synchronous (ARCHITECTURE.md §5.3): the same pull the job runs, under the same
 * lock, recorded as a manual pull; a failure is final (no retries) and recorded as the job's is. --dry-run fetches, parses and validates up to --limit
 * submissions in memory, writes nothing, and prints what a real pull would do by rule code: the
 * safe first contact with real credentials.
 */
final class Pull extends Command
{
    protected $signature = 'edqa:pull
        {--dry-run : Fetch, parse and validate in memory; write nothing}
        {--limit=200 : With --dry-run, the most submissions to fetch}';

    protected $description = 'Pull submissions from ODK Central now (or --dry-run)';

    public function handle(DryRunPull $dryRun, PullLock $lock, PullSubmissions $pull): int
    {
        return $this->option('dry-run') ? $this->dryRun($dryRun) : $this->pull($lock, $pull);
    }

    private function dryRun(DryRunPull $dryRun): int
    {
        try {
            $report = $dryRun->execute(max(1, (int) $this->option('limit')));
        } catch (OdkException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info((string) __('odk.pull.dry_run_title'));
        $this->line((string) __('odk.pull.dry_run_summary', [
            'fetched' => $report->fetched, 'stored' => $report->alreadyStored, ...$report->outcomes,
        ]));
        if ($report->rules === []) {
            $this->line((string) __('odk.pull.dry_run_no_rules'));
        } else {
            $this->table(
                [(string) __('odk.pull.rule'), (string) __('odk.pull.severity'), (string) __('odk.pull.submissions')],
                array_map(fn (string $code, array $rule): array => [$code, $rule['severity'], $rule['count']], array_keys($report->rules), $report->rules),
            );
        }

        return self::SUCCESS;
    }

    private function pull(PullLock $lock, PullSubmissions $pullSubmissions): int
    {
        try {
            $run = $lock->run(fn (): PullRun => $pullSubmissions->execute(PullTrigger::Manual), (int) config('edqa.odk.pull.lock_wait_seconds', 600));
        } catch (LockTimeoutException) {
            $this->error((string) __('odk.pull.busy'));

            return self::FAILURE;
        } catch (Throwable $e) {
            PullSubmissionsJob::recordFailure($e); // no retries from the console: this is the final failure
            $this->error((string) __('odk.pull.failed', ['error' => PullSubmissions::describe($e)]));

            return self::FAILURE;
        }

        if ($run->outcome === PullOutcome::SkippedNotConfigured) {
            $this->warn((string) __('odk.pull.skipped', ['error' => (string) $run->error]));

            return self::FAILURE;
        }

        $this->info((string) __('odk.pull.result', [
            'outcome' => $run->outcome->value ?? '',
            'fetched' => $run->fetched, 'duplicates' => $run->duplicates,
            'accepted' => $run->accepted, 'flagged' => $run->flagged, 'quarantined' => $run->quarantined,
        ]));

        return self::SUCCESS;
    }
}
