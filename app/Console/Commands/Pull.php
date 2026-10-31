<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Actions\DryRunPull;
use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\SourceException;
use App\Domain\Ingestion\Exceptions\SourceRefused;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\PullLock;
use App\Domain\Ingestion\SourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

/**
 * One pull of a source, or of every enabled source (--all), synchronous (ARCHITECTURE.md §5.3):
 * the same pull the job runs, under the same per-source lock, recorded as a manual pull; a failure
 * is final (no retries) and recorded as the job's is. --all pulls the configured and enabled
 * sources one after another, and fails if any did. --dry-run fetches, parses and validates up to
 * --limit submissions in memory, writes nothing, and prints what a real pull would do by rule
 * code: the safe first contact with real credentials.
 */
final class Pull extends Command
{
    protected $signature = 'edqa:pull
        {source? : The source to pull (odk, kobo)}
        {--all : Every configured and enabled source, one after another}
        {--dry-run : Fetch, parse and validate in memory; write nothing}
        {--limit=200 : With --dry-run, the most submissions to fetch}';

    protected $description = 'Pull submissions from a source now (or every source with --all, or --dry-run)';

    public function handle(SourceRegistry $sources, DryRunPull $dryRun, PullLock $lock, PullSubmissions $pull): int
    {
        $source = $this->argument('source');
        $all = (bool) $this->option('all');
        if (($source === null) === ! $all) {
            $this->error((string) __('sources.pull.name_one', ['sources' => implode(', ', $sources->keys())]));

            return self::FAILURE;
        }
        try {
            $keys = $all ? $sources->enabled() : [$sources->model((string) $source)->key];
        } catch (SourceRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ok = true;
        foreach ($keys as $key) {
            $this->line('<options=bold>'.$sources->name($key).'</>');
            $ok = ($this->option('dry-run') ? $this->dryRun($dryRun, $key) : $this->pull($lock, $pull, $key)) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function dryRun(DryRunPull $dryRun, string $source): bool
    {
        try {
            $report = $dryRun->execute($source, max(1, (int) $this->option('limit')));
        } catch (SourceException $e) {
            $this->error($e->getMessage());

            return false;
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

        return true;
    }

    private function pull(PullLock $lock, PullSubmissions $pullSubmissions, string $source): bool
    {
        try {
            $run = $lock->run($source, fn (): PullRun => $pullSubmissions->execute($source, PullTrigger::Manual), PullLock::waitSeconds());
        } catch (LockTimeoutException) {
            $this->error((string) __('odk.pull.busy'));

            return false;
        } catch (Throwable $e) {
            PullSubmissionsJob::recordFailure($source, $e); // no retries from the console: this is the final failure
            $this->error((string) __('odk.pull.failed', ['error' => PullSubmissions::describe($e)]));

            return false;
        }

        if ($run->outcome === PullOutcome::SkippedNotConfigured) {
            $this->warn((string) __('odk.pull.skipped', ['error' => (string) $run->error]));

            return false;
        }

        $this->info((string) __('odk.pull.result', [
            'outcome' => $run->outcome->value ?? '',
            'fetched' => $run->fetched, 'duplicates' => $run->duplicates,
            'accepted' => $run->accepted, 'flagged' => $run->flagged, 'quarantined' => $run->quarantined,
        ]));
        if ($run->cross_source_duplicates > 0) {
            $this->line((string) __('sources.pull.cross_source', ['count' => $run->cross_source_duplicates]));
        }

        return true;
    }
}
