<?php

declare(strict_types=1);

use App\Domain\Assessment\Events\AssessmentScored;
use App\Domain\Reporting\Jobs\RefreshRoundAggregatesJob;
use App\Domain\Reporting\RoundCache;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ReportingFixture;

it('refreshes the view, then flushes the round cache', function (): void {
    $f = ReportingFixture::build();
    $calls = 0;
    app(RoundCache::class)->remember([$f->q2], 'probe', function () use (&$calls): int {
        return ++$calls;
    });
    expect(DB::selectOne("SELECT ispopulated FROM pg_matviews WHERE matviewname = 'round_aggregates'")->ispopulated)->toBeFalse();

    app()->call([new RefreshRoundAggregatesJob($f->q2), 'handle']);

    expect(DB::selectOne("SELECT ispopulated FROM pg_matviews WHERE matviewname = 'round_aggregates'")->ispopulated)->toBeTrue()
        ->and(DB::table('round_aggregates')->where(['round_id' => $f->q2, 'scope_type' => 'state', 'owner_type' => 'all', 'level' => 'all'])->value('facility_count'))->toBe(7)
        ->and(app(RoundCache::class)->remember([$f->q2], 'probe', fn (): int => ++$calls))->toBe(2);
});

it('is debounced: unique per round for 60 seconds, released when it starts', function (): void {
    $job = new RefreshRoundAggregatesJob(12);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueFor)->toBe(60)
        ->and($job->uniqueId())->toBe('round:12')
        ->and($job->delay)->not->toBeNull();
});

it('queues one refresh for a burst of scores in the same round', function (): void {
    Queue::fake();

    foreach (range(1, 5) as $i) {
        event(new AssessmentScored(assessmentId: $i, roundId: 3, ruleVersionId: 1));
    }
    event(new AssessmentScored(assessmentId: 6, roundId: 4, ruleVersionId: 1));

    Queue::assertPushed(RefreshRoundAggregatesJob::class, 2);
    Queue::assertPushed(RefreshRoundAggregatesJob::class, fn (RefreshRoundAggregatesJob $job): bool => $job->roundId === 3);
    Queue::assertPushed(RefreshRoundAggregatesJob::class, fn (RefreshRoundAggregatesJob $job): bool => $job->roundId === 4);
});

it('can be dispatched directly, as rescore and round close will', function (): void {
    Queue::fake();

    RefreshRoundAggregatesJob::debounced(9);
    RefreshRoundAggregatesJob::debounced(9);

    Queue::assertPushed(RefreshRoundAggregatesJob::class, 1);
});

it('queues a refresh when a round closes, not on other round saves', function (): void {
    $f = ReportingFixture::build();
    Queue::fake();
    $round = Round::query()->findOrFail($f->q2);

    $round->update(['window_end' => '2026-07-01']);
    Queue::assertNothingPushed();

    $round->update(['status' => RoundStatus::Closed, 'rule_version_id' => $f->v2, 'closed_at' => now()]);
    Queue::assertPushed(RefreshRoundAggregatesJob::class, fn (RefreshRoundAggregatesJob $job): bool => $job->roundId === $f->q2);
});
