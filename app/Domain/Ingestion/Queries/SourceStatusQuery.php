<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\Actions\StartManualPull;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\SourceException;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\SourceRegistry;
use Illuminate\Support\Facades\Cache;

/**
 * The ingestion half of one source's entry in GET /api/sources (ARCHITECTURE.md §8): whether it
 * is configured (and which env keys are missing, never their values) and enabled, whether it
 * answers, where its cursors stand, its pull and sweep in progress and its last pull.
 * Reachability is a real call (SubmissionSource::ping), cached for REACHABLE_SECONDS per source so
 * a page polling the status does not call the source each time.
 */
final class SourceStatusQuery
{
    public const REACHABLE_SECONDS = 60;

    public function __construct(
        private readonly SourceRegistry $sources,
    ) {}

    public static function cacheKey(string $source): string
    {
        return "edqa.sources.{$source}.reachable";
    }

    /**
     * @return array{key: string, name: string, type: string, configured: bool, problems: list<string>, enabled: bool, reachable: ?array{ok: bool, status: string, message: ?string, checked_at: string}, cursor: ?array{last_pulled_at: ?string, last_edit_at: ?string, last_instance_id: ?string, backfill_skip: int, submission_count: int}, last_reconciled_at: ?string, running_run_id: ?int, running_reconcile_run_id: ?int, last_run: ?array{id: int, outcome: ?string, finished_at: ?string, fetched: int, parsed: int, accepted: int, flagged: int, quarantined: int, duplicates: int, cross_source_duplicates: int}}
     */
    public function status(string $source): array
    {
        $problems = $this->sources->problems($source);
        $configured = $problems === [];
        $sourceId = $this->sources->idOrNull($source);
        $sync = $configured && $sourceId !== null ? SourceSync::current($sourceId, $this->sources->formRef($source)) : null;

        return [
            'key' => $source,
            'name' => $this->sources->name($source),
            'type' => $this->sources->type($source)->value,
            'configured' => $configured,
            'problems' => $problems,
            'enabled' => in_array($source, $this->sources->enabled(), true),
            'reachable' => $configured ? $this->reachable($source) : null,
            'cursor' => $sync === null ? null : [
                'last_pulled_at' => $sync->cursor_at?->toIso8601String(),
                'last_edit_at' => $sync->edit_cursor_at?->toIso8601String(),
                'last_instance_id' => is_string($sync->cursor_instance_id) ? $sync->cursor_instance_id : null,
                'backfill_skip' => (int) $sync->backfill_checkpoint,
                'submission_count' => (int) $sync->submission_count,
            ],
            'last_reconciled_at' => $sync?->last_reconciled_at?->toIso8601String(),
            'running_run_id' => $sourceId === null ? null : StartManualPull::running($sourceId)?->id,
            'running_reconcile_run_id' => $sourceId === null ? null : StartManualPull::running($sourceId, reconcile: true)?->id,
            'last_run' => $sourceId === null ? null : self::lastRun($sourceId),
        ];
    }

    /**
     * The source's last finished pull (not a backfill or a sweep): "fetched in the last pull".
     *
     * @return array{id: int, outcome: ?string, finished_at: ?string, fetched: int, parsed: int, accepted: int, flagged: int, quarantined: int, duplicates: int, cross_source_duplicates: int}|null
     */
    private static function lastRun(int $sourceId): ?array
    {
        $run = PullRun::query()->where('source_id', $sourceId)->whereNotNull('finished_at')
            ->whereNotIn('trigger_type', [PullTrigger::Backfill, PullTrigger::Reconcile])
            ->whereIn('outcome', [PullOutcome::Succeeded, PullOutcome::Failed])->latest('finished_at')->latest('id')->first();

        return $run === null ? null : [
            'id' => $run->id, 'outcome' => $run->outcome?->value, 'finished_at' => $run->finished_at?->toIso8601String(),
            'fetched' => $run->fetched, 'parsed' => $run->parsed, 'accepted' => $run->accepted, 'flagged' => $run->flagged,
            'quarantined' => $run->quarantined, 'duplicates' => $run->duplicates, 'cross_source_duplicates' => (int) $run->cross_source_duplicates,
        ];
    }

    /** @return array{ok: bool, status: string, message: ?string, checked_at: string} */
    private function reachable(string $source): array
    {
        /** @var array{ok: bool, status: string, message: ?string, checked_at: string} */
        return Cache::remember(self::cacheKey($source), self::REACHABLE_SECONDS, function () use ($source): array {
            try {
                $this->sources->adapter($source)->ping();

                return ['ok' => true, 'status' => 'ok', 'message' => null, 'checked_at' => now()->toIso8601String()];
            } catch (SourceException $e) {
                return ['ok' => false, 'status' => $e->status(), 'message' => $e->getMessage(), 'checked_at' => now()->toIso8601String()];
            }
        });
    }
}
