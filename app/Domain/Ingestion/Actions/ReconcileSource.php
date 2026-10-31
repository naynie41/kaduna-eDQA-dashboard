<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\DTOs\SubmissionEnvelope;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Events\SourceDeletionDetected;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Round\Models\Round;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The reconcile sweep of one source (ARCHITECTURE.md §5.3, D-61, D-62): every submission the
 * source holds for the open round's window (received on or after the window's first day, local),
 * compared by (external ID, instance ID) with what we hold. The caller holds the source's PullLock.
 *
 * - An instance we do not hold is processed through ProcessSubmissionsPage, the pull's own path:
 *   for a known external ID it is an edit (the old version superseded, the assessment updated in
 *   place), else a submission the pull missed. This is the only way an edit on Kobo is found: Kobo
 *   keeps its _submission_time and exposes no last-edited filter.
 * - A submission we hold for the window (its latest version, not yet flagged) whose external ID
 *   the source no longer returns is deleted at source: deleted_at_source is set, the flag is
 *   audited and SourceDeletionDetected raised. Nothing else changes (D-62): its status, payload,
 *   assessment and scores stay, for an administrator to decide.
 * - The whole window is read before anything is written, so a sweep that fails part-way flags
 *   nothing. The cursors do not move; last_reconciled_at does.
 *
 * Progress is one pull_runs row, trigger reconcile: fetched is every row the source returned,
 * deletions_detected the submissions newly flagged. A run created when an administrator asked for
 * the sweep (StartReconcile) is carried on; otherwise one is created.
 */
final class ReconcileSource
{
    public function __construct(
        private readonly ProcessSubmissionsPage $page,
        private readonly SourceRegistry $sources,
    ) {}

    public function execute(string $source, ?int $userId = null, ?int $runId = null): PullRun
    {
        $sourceId = $this->sources->id($source);
        $run = $runId === null ? null : PullRun::query()->whereKey($runId)->where('source_id', $sourceId)->whereNull('finished_at')->first();
        $run ??= PullRun::query()->create(['source_id' => $sourceId, 'trigger_type' => PullTrigger::Reconcile, 'triggered_by' => $userId, 'started_at' => now()]);

        if ($this->sources->problems($source) !== []) {
            return $this->finish($run, PullOutcome::SkippedNotConfigured, $this->sources->notConfigured($source)->getMessage());
        }
        $since = self::windowStart();
        if ($since === null) {
            return $this->finish($run, PullOutcome::Succeeded, null); // no open round: nothing to compare
        }

        $sync = SourceSync::forForm($sourceId, $this->sources->formRef($source));
        try {
            [$rows, $envelopes] = $this->read($source, $since);
            $held = Submission::query()->where('source_id', $sourceId)
                ->whereIn('instance_id', array_map(fn (SubmissionEnvelope $e): string => $e->meta->instanceId, $envelopes))
                ->pluck('instance_id')->flip()->all();

            $unheld = [];
            foreach ($rows as $i => $row) {
                if ($envelopes[$i]->meta->instanceId !== '' && ! isset($held[$envelopes[$i]->meta->instanceId])) {
                    $unheld[] = $row;
                }
            }
            foreach (array_chunk($unheld, $this->sources->pageSize($source)) as $chunk) {
                $this->page->execute($run, $sync, $chunk);
            }

            $present = [];
            foreach ($envelopes as $envelope) {
                if ($envelope->meta->externalId !== null) {
                    $present[$envelope->meta->externalId] = true;
                }
            }
            $deleted = $this->flagDeletions($source, $sourceId, $since, $present);
        } catch (Throwable $e) {
            $this->finish($run, PullOutcome::Failed, PullSubmissions::describe($e));

            throw $e;
        }

        $run->update(['fetched' => count($rows), 'deletions_detected' => $deleted]);
        $sync->update(['last_reconciled_at' => now()]);

        return $this->finish($run, PullOutcome::Succeeded, null);
    }

    /** The open round's first day, local midnight; null when no round is open. */
    public static function windowStart(): ?CarbonImmutable
    {
        $round = Round::query()->where('status', 'open')->orderByDesc('year')->orderByDesc('quarter')->first();

        return $round === null ? null : CarbonImmutable::parse($round->window_start->toDateString(), (string) config('app.timezone'));
    }

    /**
     * Every row the source returns since $since, each with its envelope.
     *
     * @return array{0: list<array<array-key, mixed>>, 1: list<SubmissionEnvelope>}
     */
    private function read(string $source, CarbonImmutable $since): array
    {
        $adapter = $this->sources->adapter($source);
        $normaliser = $this->sources->normaliser($source);
        $top = $this->sources->pageSize($source);
        $rows = [];
        for ($skip = 0; ; $skip += count($page->rows)) {
            $page = $adapter->submissionsPage($since, $top, $skip, inclusive: true);
            array_push($rows, ...$page->rows);
            if (! $page->hasMore || $page->rows === []) {
                break;
            }
        }

        return [$rows, array_map($normaliser->normalise(...), $rows)];
    }

    /**
     * Flags what we hold for the window that the source no longer returns.
     *
     * @param  array<string, true>  $present  the external IDs the source returned
     */
    private function flagDeletions(string $source, int $sourceId, CarbonImmutable $since, array $present): int
    {
        $gone = Submission::query()
            ->where('source_id', $sourceId)
            ->where('submitted_at', '>=', $since)
            ->whereNull('superseded_by_id')
            ->where('status', '<>', SubmissionStatus::Superseded)
            ->whereNull('deleted_at_source')
            ->whereNotNull('external_id')
            ->orderBy('id')
            ->get(['id', 'external_id', 'source_id', 'instance_id'])
            ->filter(fn (Submission $s): bool => ! isset($present[(string) $s->external_id]))
            ->values();
        if ($gone->isEmpty()) {
            return 0;
        }

        $at = now();
        DB::transaction(function () use ($gone, $at): void {
            foreach ($gone as $submission) {
                // Only the flag: never the payload (hard rule 8), the status or the assessment (D-62).
                DB::table('submissions')->where('id', $submission->id)->update(['deleted_at_source' => $at]);
                AuditLog::record($submission, null, 'deleted_at_source', old: ['deleted_at_source' => null], new: ['deleted_at_source' => $at->toIso8601String()]);
            }
        });
        foreach ($gone as $submission) {
            SourceDeletionDetected::dispatch($submission->id, $source, (string) $submission->external_id);
        }

        return $gone->count();
    }

    private function finish(PullRun $run, PullOutcome $outcome, ?string $error): PullRun
    {
        $run->update(['outcome' => $outcome, 'finished_at' => now(), 'error' => $error]);

        return $run;
    }
}
