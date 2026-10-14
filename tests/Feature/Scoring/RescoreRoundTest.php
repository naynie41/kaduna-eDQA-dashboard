<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Reporting\Jobs\RefreshRoundAggregatesJob;
use App\Domain\Reporting\RoundCache;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Actions\CreateDraftRuleVersion;
use App\Domain\Scoring\Actions\PublishRuleVersion;
use App\Domain\Scoring\Actions\RescoreAssessments;
use App\Domain\Scoring\Actions\RescoreRound;
use App\Domain\Scoring\Enums\RescoreStatus;
use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Domain\Scoring\Exceptions\RescoreRefused;
use App\Domain\Scoring\Jobs\RescoreRoundJob;
use App\Domain\Scoring\Models\RescoreRun;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Actions\OverrideSoftFlag;
use App\Models\User;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Testing\Fakes\BatchFake;
use Tests\Support\RescoreFixture;

beforeEach(function (): void {
    $this->f = RescoreFixture::build();
    config(['edqa.rescore.chunk_size' => 2]);
});

function rescore(int $round): RescoreRun
{
    return app(RescoreRound::class)->start(Round::query()->findOrFail($round), RescoreTrigger::Console);
}

/** Audit entries a rescore writes, leaving out the one that records the request itself. */
function scoreAuditCount(): int
{
    return DB::table('activity_log')->where('event', '<>', 'rescore_requested')->count();
}

it('scores every accepted assessment of an open round under its version, in chunks', function (): void {
    foreach (range(1, 3) as $i) {
        $this->f->assessment($this->f->q2);
    }

    $run = rescore($this->f->q2)->refresh();

    $rows = RescoreFixture::rows($this->f->q2);
    expect($rows)->toHaveCount(27)
        ->and(array_unique(array_column($rows, 'rule_version_id')))->toBe([$this->f->v1])
        ->and(array_unique(array_column($rows, 'score')))->toBe(['50.00'])
        ->and($run->status)->toBe(RescoreStatus::Succeeded)
        ->and($run->rule_version_id)->toBe($this->f->v1)
        ->and($run->total_assessments)->toBe(3)
        ->and($run->processed_assessments)->toBe(3)
        ->and($run->changed_scores)->toBe(27)
        ->and($run->finished_at)->not->toBeNull();

    $batch = Bus::findBatch((string) $run->batch_id);
    expect($batch->totalJobs)->toBe(2)
        ->and($batch->processedJobs())->toBe(2)
        ->and($batch->failedJobs)->toBe(0)
        ->and($batch->finished())->toBeTrue();
});

it('leaves out assessments that do not count and is refused for a closed round', function (): void {
    $this->f->assessment($this->f->q2, attributes: ['status' => 'withdrawn']);
    $closedAssessment = $this->f->assessment($this->f->q1);

    expect(rescore($this->f->q2)->refresh()->total_assessments)->toBe(0)
        ->and(RescoreFixture::rows($this->f->q2))->toBe([]);

    expect(fn () => rescore($this->f->q1))->toThrow(RescoreRefused::class, __('scoring.errors.round_closed'));
    expect(RescoreRun::query()->where('round_id', $this->f->q1)->exists())->toBeFalse()
        ->and(DB::table('assessment_scores')->where('assessment_id', $closedAssessment)->exists())->toBeFalse();
});

it('is deterministic: a second rescore writes nothing, not even computed_at', function (): void {
    $this->f->assessment($this->f->q2);
    $this->f->assessment($this->f->q2, slots: ['validity.3' => ['na', 'na']]);
    $this->f->assessment($this->f->q2, default: 'yes/yes');
    rescore($this->f->q2);
    $before = RescoreFixture::rows($this->f->q2);
    $flags = Assessment::query()->orderBy('id')->get(['id', 'flags', 'status', 'updated_at'])->toArray();
    $audit = scoreAuditCount();

    $this->travel(2)->hours();
    $second = rescore($this->f->q2)->refresh();

    expect(RescoreFixture::rows($this->f->q2))->toBe($before)
        ->and(Assessment::query()->orderBy('id')->get(['id', 'flags', 'status', 'updated_at'])->toArray())->toBe($flags)
        ->and(scoreAuditCount())->toBe($audit)
        ->and($second->changed_scores)->toBe(0)
        ->and($second->changed_flags)->toBe(0)
        ->and($second->processed_assessments)->toBe(3);
});

it('after a publish, writes the new version and changes exactly the rows the new weight moves', function (): void {
    // item_b weighs 3 in availability under v2: a slot moves only when item_a and item_b disagree.
    $mixed = $this->f->assessment($this->f->q2);                                     // yes/no → 50, then 25
    $agree = $this->f->assessment($this->f->q2, default: 'yes/yes');                // 100 either way
    $oneNa = $this->f->assessment($this->f->q2, slots: ['availability.2' => ['yes', 'na']]); // slot 2 stays 100
    rescore($this->f->q2);
    $v1Rows = RescoreFixture::rows($this->f->q2, $this->f->v1);

    $admin = User::query()->findOrFail($this->f->admin);
    $from = ScoringRuleVersion::query()->findOrFail($this->f->v1);
    $draft = app(CreateDraftRuleVersion::class)->execute($from, ['item_weights' => ['availability.item_b' => 3]], $admin);
    app(PublishRuleVersion::class)->execute($draft, $admin, 'Item b counts triple in availability');

    // The publish trigger rescored the open round (sync queue in tests).
    $v2Rows = collect(RescoreFixture::rows($this->f->q2, $draft->id))->keyBy(fn (array $r): string => "{$r['assessment_id']}:{$r['dimension']}:{$r['month_slot']}");
    $changed = [];
    foreach ($v1Rows as $row) {
        $key = "{$row['assessment_id']}:{$row['dimension']}:{$row['month_slot']}";
        if ($v2Rows[$key]['score'] !== $row['score']) {
            $changed[$key] = $v2Rows[$key]['score'];
        }
    }

    expect($v2Rows)->toHaveCount(27)
        ->and(RescoreFixture::rows($this->f->q2, $this->f->v1))->toBe($v1Rows) // old version untouched
        ->and($changed)->toBe([
            "{$mixed}:availability:1" => '25.00',
            "{$mixed}:availability:2" => '25.00',
            "{$mixed}:availability:3" => '25.00',
            "{$oneNa}:availability:1" => '25.00',
            "{$oneNa}:availability:3" => '25.00',
        ])
        ->and($agree)->toBeInt();
});

it('recomputes ALL_PERFECT and the all-N/A notes, keeps other soft flags, and sets the status', function (): void {
    $perfect = $this->f->assessment($this->f->q2, default: 'yes/yes', attributes: ['status' => 'flagged', 'flags' => json_encode(['VISIT_TOO_SHORT'])]);
    $stale = $this->f->assessment($this->f->q2, slots: ['validity.3' => ['na', 'na']], attributes: ['status' => 'flagged', 'flags' => json_encode(['ALL_PERFECT'])]);

    $run = rescore($this->f->q2)->refresh();

    expect(Assessment::query()->findOrFail($perfect))
        ->flags->toBe(['ALL_PERFECT', 'VISIT_TOO_SHORT']) // the pipeline's rule order, as ingestion writes them
        ->status->toBe('flagged');
    expect(Assessment::query()->findOrFail($stale))
        ->flags->toBe(['SLOT_ALL_NA:validity:3'])
        ->status->toBe('accepted');
    expect($run->changed_flags)->toBe(2)
        ->and(DB::table('activity_log')->where('subject_type', (new Assessment)->getMorphClass())->where('subject_id', $stale)->where('event', 'updated')->exists())->toBeTrue();
});

it('keeps an override: a flag an administrator cleared is not raised again', function (): void {
    $perfect = $this->f->assessment($this->f->q2, default: 'yes/yes');
    rescore($this->f->q2);
    expect(Assessment::query()->findOrFail($perfect)->flags)->toBe(['ALL_PERFECT']);

    app(OverrideSoftFlag::class)->execute(Assessment::query()->findOrFail($perfect), 'ALL_PERFECT', User::query()->findOrFail($this->f->admin), 'Visited in person, all registers complete');
    rescore($this->f->q2);

    expect(Assessment::query()->findOrFail($perfect))
        ->flags->toBe([])
        ->status->toBe('accepted');
});

it("raises SCORE_JUMP against the facility's previous round, read under that round's own version", function (): void {
    $jumping = $this->f->facility();
    $steady = $this->f->facility();
    RescoreFixture::scores($this->f->assessment($this->f->q1, $jumping), $this->f->v1, 100);
    RescoreFixture::scores($this->f->assessment($this->f->q1, $steady), $this->f->v1, 60);
    $dropped = $this->f->assessment($this->f->q2, $jumping, default: 'no/no');   // 100 → 0
    $held = $this->f->assessment($this->f->q2, $steady);                         // 60 → 50
    $first = $this->f->assessment($this->f->q2, default: 'no/no');               // no earlier round

    rescore($this->f->q2);

    expect(Assessment::query()->findOrFail($dropped)->flags)->toBe(['SCORE_JUMP'])
        ->and(Assessment::query()->findOrFail($held)->flags)->toBe([])
        ->and(Assessment::query()->findOrFail($first)->flags)->toBe([]);
});

it('rolls a failed chunk back, then retries it without duplicates', function (): void {
    $ids = [$this->f->assessment($this->f->q2), $this->f->assessment($this->f->q2)];
    // A crash in an earlier attempt left part of the first assessment's rows behind.
    DB::table('assessment_scores')->insert(['assessment_id' => $ids[0], 'dimension' => 'availability', 'month_slot' => 1,
        'score' => 1, 'rule_version_id' => $this->f->v1, 'computed_at' => now()]);
    $run = RescoreRun::query()->create(['round_id' => $this->f->q2, 'rule_version_id' => $this->f->v1, 'trigger' => RescoreTrigger::Console,
        'status' => RescoreStatus::Running, 'total_assessments' => 2, 'started_at' => now()]);

    DB::unprepared(<<<'SQL'
        CREATE FUNCTION test_fail_progress() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'worker lost'; END $$;
        CREATE TRIGGER test_fail_progress BEFORE UPDATE ON rescore_runs FOR EACH ROW EXECUTE FUNCTION test_fail_progress();
        SQL);
    expect(fn () => app(RescoreAssessments::class)->execute($run->id, 0, $ids))->toThrow(QueryException::class);
    expect(RescoreFixture::rows($this->f->q2))->toHaveCount(1)
        ->and($run->refresh()->processed_assessments)->toBe(0);

    DB::unprepared('DROP TRIGGER test_fail_progress ON rescore_runs; DROP FUNCTION test_fail_progress();');
    app(RescoreAssessments::class)->execute($run->id, 0, $ids);
    app(RescoreAssessments::class)->execute($run->id, 0, $ids); // delivered twice: still counted once

    $rows = RescoreFixture::rows($this->f->q2);
    expect($rows)->toHaveCount(18)
        ->and(array_unique(array_column($rows, 'score')))->toBe(['50.00'])
        ->and($run->refresh()->processed_assessments)->toBe(2)
        ->and($run->changed_scores)->toBe(18);
});

it('queues 200-assessment chunks on the rescore queue and finishes the run from the batch', function (): void {
    config(['edqa.rescore.chunk_size' => 200]);
    foreach (range(1, 3) as $i) {
        $this->f->assessment($this->f->q2);
    }
    Bus::fake();

    $run = rescore($this->f->q2);

    $pending = null;
    Bus::assertBatched(function (PendingBatch $batch) use (&$pending): bool {
        $pending = $batch;

        return true;
    });
    expect($pending->queue())->toBe('rescore')
        ->and($pending->allowsFailures())->toBeTrue()
        ->and($pending->jobs)->toHaveCount(1)
        ->and($pending->jobs->first())->toBeInstanceOf(RescoreRoundJob::class)
        ->and($pending->jobs->first()->assessmentIds)->toHaveCount(3)
        ->and($run->refresh()->batch_id)->not->toBeNull();

    $calls = 0;
    app(RoundCache::class)->remember([$this->f->q2], 'probe', function () use (&$calls): int {
        return ++$calls;
    });

    // One chunk failed: the run is failed, and what was written is still published.
    $failed = new BatchFake((string) $run->batch_id, 'rescore', 1, 1, 1, ['job-1'], [], now()->toImmutable());
    foreach ($pending->finallyCallbacks() as $callback) {
        $callback($failed);
    }
    expect($run->refresh()->status)->toBe(RescoreStatus::Failed)
        ->and($run->failed_chunks)->toBe(1)
        ->and(app(RoundCache::class)->remember([$this->f->q2], 'probe', fn (): int => ++$calls))->toBe(2);
    Bus::assertDispatched(RefreshRoundAggregatesJob::class, fn (RefreshRoundAggregatesJob $job): bool => $job->roundId === $this->f->q2);

    // The retry passed.
    $retried = new BatchFake((string) $run->batch_id, 'rescore', 1, 0, 1, [], [], now()->toImmutable());
    foreach ($pending->thenCallbacks() as $callback) {
        $callback($retried);
    }
    expect($run->refresh()->status)->toBe(RescoreStatus::Succeeded)
        ->and($run->failed_chunks)->toBe(0);
});

it('audits each request with its trigger', function (): void {
    $run = app(RescoreRound::class)->start(Round::query()->findOrFail($this->f->q2), RescoreTrigger::Manual, User::query()->findOrFail($this->f->admin), '  Corrected facility list  ');

    $entry = DB::table('activity_log')->where('event', 'rescore_requested')->sole();
    $properties = json_decode((string) $entry->properties, true);
    expect($entry->subject_id)->toBe($this->f->q2)
        ->and($entry->causer_id)->toBe($this->f->admin)
        ->and($properties['reason'])->toBe('Corrected facility list')
        ->and($properties['attributes'])->toEqual(['run_id' => $run->id, 'rule_version_id' => $this->f->v1, 'trigger' => 'manual'])
        ->and($run->refresh()->reason)->toBe('Corrected facility list');
});
