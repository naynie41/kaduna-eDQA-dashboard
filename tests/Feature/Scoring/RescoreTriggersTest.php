<?php

declare(strict_types=1);

use App\Domain\Assessment\Events\AssessmentCorrected;
use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Domain\Scoring\Events\RuleVersionPublished;
use App\Domain\Scoring\Jobs\RequestRescoreJob;
use App\Domain\Scoring\Listeners\RescoreAfterAssessmentCorrected;
use App\Domain\Scoring\Listeners\RescoreOpenRoundsAfterPublish;
use App\Domain\Scoring\Models\RescoreRun;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Listeners\RescoreAfterQuarantineResolved;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use Tests\Support\RescoreFixture;
use Tests\Support\Rows;

beforeEach(function (): void {
    $this->f = RescoreFixture::build();
});

it('wires the three automatic triggers', function (): void {
    Event::fake();

    Event::assertListening(RuleVersionPublished::class, RescoreOpenRoundsAfterPublish::class);
    Event::assertListening(QuarantineResolved::class, RescoreAfterQuarantineResolved::class);
    Event::assertListening(AssessmentCorrected::class, RescoreAfterAssessmentCorrected::class);
});

it('asks for a rescore of every open round, and no closed one, after a publish', function (): void {
    $alsoOpen = Rows::round(['year' => 2026, 'quarter' => 3, 'window_start' => '2026-07-01', 'window_end' => '2026-09-30']);
    Queue::fake();

    event(new RuleVersionPublished($this->f->v1));

    Queue::assertPushed(RequestRescoreJob::class, 2);
    Queue::assertPushedOn('rescore', RequestRescoreJob::class);
    foreach ([$this->f->q2, $alsoOpen] as $round) {
        Queue::assertPushed(RequestRescoreJob::class, fn (RequestRescoreJob $job): bool => $job->roundId === $round && $job->trigger === RescoreTrigger::Publish);
    }
});

it("asks for a rescore of a resolved record's round, and of a corrected assessment's", function (): void {
    Queue::fake();

    event(new QuarantineResolved(7, $this->f->q2, QuarantineResolution::Rejected));
    event(new QuarantineResolved(8, null, QuarantineResolution::Rejected)); // never placed in a round
    event(new AssessmentCorrected(assessmentId: 3, roundId: $this->f->q1));

    Queue::assertPushed(RequestRescoreJob::class, 2);
    Queue::assertPushed(RequestRescoreJob::class, fn (RequestRescoreJob $job): bool => $job->roundId === $this->f->q2 && $job->trigger === RescoreTrigger::Quarantine);
    Queue::assertPushed(RequestRescoreJob::class, fn (RequestRescoreJob $job): bool => $job->roundId === $this->f->q1 && $job->trigger === RescoreTrigger::Correction);
});

it('debounces: a burst of triggers for one round queues one request', function (): void {
    Queue::fake();

    foreach (range(1, 5) as $i) {
        event(new QuarantineResolved($i, $this->f->q2, QuarantineResolution::Reprocessed));
    }

    Queue::assertPushed(RequestRescoreJob::class, 1);
    $job = new RequestRescoreJob($this->f->q2, RescoreTrigger::Quarantine);
    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe("round:{$this->f->q2}")
        ->and($job->delay)->not->toBeNull();
});

it('starts the rescore when the request runs, and skips a round closed in the meantime', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->once()->withArgs(fn (string $message, array $context): bool => $message === 'rescore.skipped_closed_round'
        && $context['round_id'] === $this->f->q1 && $context['trigger'] === 'correction');
    Log::shouldReceive('channel')->with('edqa')->andReturn($logger);

    app()->call([new RequestRescoreJob($this->f->q2, RescoreTrigger::Quarantine), 'handle']);
    app()->call([new RequestRescoreJob($this->f->q1, RescoreTrigger::Correction), 'handle']);

    expect(RescoreRun::query()->pluck('trigger', 'round_id')->map->value->all())->toBe([$this->f->q2 => 'quarantine']);
});
