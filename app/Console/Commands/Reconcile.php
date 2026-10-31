<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Actions\ReconcileSource;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Exceptions\SourceRefused;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\PullLock;
use App\Domain\Ingestion\SourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

/**
 * The reconcile sweep of one source for the open round, synchronous (ARCHITECTURE.md §5.3, D-61,
 * D-62): the sweep ReconcileSourceJob runs nightly, under the same per-source lock. Edits are
 * processed; a submission deleted at source is only flagged.
 */
final class Reconcile extends Command
{
    protected $signature = 'edqa:reconcile {source : The source to reconcile (odk, kobo)}';

    protected $description = 'Compare the open round with a source now: process edits, flag deletions at source';

    public function handle(SourceRegistry $sources, PullLock $lock, ReconcileSource $reconcile): int
    {
        $source = (string) $this->argument('source');
        try {
            $sources->model($source);
        } catch (SourceRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (ReconcileSource::windowStart() === null) {
            $this->warn((string) __('sources.reconcile.no_round'));
        }

        try {
            $run = $lock->run($source, fn (): PullRun => $reconcile->execute($source), PullLock::waitSeconds());
        } catch (LockTimeoutException) {
            $this->error((string) __('sources.reconcile.busy'));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error((string) __('sources.reconcile.failed', ['error' => PullSubmissions::describe($e)]));

            return self::FAILURE;
        }

        if ($run->outcome === PullOutcome::SkippedNotConfigured) {
            $this->warn((string) __('odk.pull.skipped', ['error' => (string) $run->error]));

            return self::FAILURE;
        }
        $this->info((string) __('sources.reconcile.done', [
            'fetched' => $run->fetched, 'parsed' => $run->parsed, 'accepted' => $run->accepted,
            'flagged' => $run->flagged, 'quarantined' => $run->quarantined, 'deleted' => $run->deletions_detected,
        ]));

        return self::SUCCESS;
    }
}
