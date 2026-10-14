<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Actions;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\RescoreStatus;
use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Domain\Scoring\Exceptions\RescoreRefused;
use App\Domain\Scoring\Jobs\RescoreRoundJob;
use App\Domain\Scoring\Models\RescoreRun;
use App\Domain\Scoring\RuleVersionForRound;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Bus\Batch;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Starts a rescore of one round (ARCHITECTURE.md §7): every accepted or flagged assessment,
 * under the round's version (RuleVersionForRound: the current published one, as the round is
 * open), as a Bus batch of RescoreRoundJob chunks on the 'rescore' queue. Progress lives on the
 * batch and on the rescore_runs row; the request is audited on the round.
 *
 * A closed round is refused (D-32: it keeps its pinned scores until reopened). A manual request
 * needs an administrator and a reason.
 */
final class RescoreRound
{
    public const QUEUE = 'rescore';

    public const MIN_REASON = 10;

    public function __construct(
        private readonly RuleVersionForRound $versions,
        #[Config('edqa.rescore.chunk_size')] private readonly int $chunkSize,
    ) {}

    public function start(Round $round, RescoreTrigger $trigger, ?User $actor = null, ?string $reason = null): RescoreRun
    {
        $round = Round::query()->findOrFail($round->id);
        if ($round->status === RoundStatus::Closed) {
            throw RescoreRefused::roundClosed();
        }

        $reason = $reason === null ? null : trim($reason);
        if ($trigger === RescoreTrigger::Manual && ($actor === null || mb_strlen((string) $reason) < self::MIN_REASON)) {
            throw RescoreRefused::reasonTooShort();
        }

        $versionId = $this->versions->id($round);
        $assessmentIds = Assessment::query()
            ->where('round_id', $round->id)
            ->whereIn('status', ['accepted', 'flagged'])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $run = DB::transaction(function () use ($round, $trigger, $actor, $reason, $versionId, $assessmentIds): RescoreRun {
            $run = RescoreRun::query()->create([
                'round_id' => $round->id,
                'rule_version_id' => $versionId,
                'trigger' => $trigger,
                'requested_by' => $actor?->id,
                'reason' => $reason,
                'status' => RescoreStatus::Running,
                'total_assessments' => count($assessmentIds),
                'started_at' => now(),
            ]);

            AuditLog::record($round, $actor, 'rescore_requested',
                old: [],
                new: ['run_id' => $run->id, 'rule_version_id' => $versionId, 'trigger' => $trigger->value],
                reason: $reason,
            );

            return $run;
        });

        // An empty round still gets one (empty) chunk, so the batch finishes and closes the run.
        $chunks = array_chunk($assessmentIds, max(1, $this->chunkSize)) ?: [[]];
        $jobs = [];
        foreach ($chunks as $index => $ids) {
            $jobs[] = new RescoreRoundJob($run->id, $index, $ids);
        }

        $runId = $run->id;
        $batch = Bus::batch($jobs)
            ->name("rescore:round:{$round->id}")
            ->onQueue(self::QUEUE)
            ->allowFailures()
            ->then(static fn (Batch $batch) => app(FinishRescore::class)->execute($runId, $batch))
            ->finally(static fn (Batch $batch) => app(FinishRescore::class)->execute($runId, $batch))
            ->dispatch();

        // Only the batch id: on the sync queue the batch has already finished the run.
        RescoreRun::query()->whereKey($runId)->update(['batch_id' => $batch->id]);

        return $run->refresh();
    }
}
