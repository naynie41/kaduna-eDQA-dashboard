<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Ingestion\Actions\BackfillSubmissions;
use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Actions\ReconcileSource;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Events\SourceDeletionDetected;
use App\Domain\Ingestion\Jobs\ReconcileSourceJob;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\PullLock;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\CurrentRuleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeKoboSource;
use Tests\Support\FakeSubmissionSource;
use Tests\Support\FixtureWorld;
use Tests\Support\Rows;

// The reconcile sweep (ARCHITECTURE.md §5.3, D-61, D-62): the open round's window read again
// from the source, compared by (external ID, instance ID) with what is held. A new instance of a
// known submission is processed as an edit (Kobo's only edit path: an edit keeps its
// _submission_time); one held but gone at source is flagged, nothing else.

beforeEach(function (): void {
    FixtureWorld::build();
    CurrentRuleVersion::forget();
    $chikun = (int) DB::table('lgas')->where('code', 'CHK')->value('id');
    $kujama = (int) DB::table('wards')->where('code', 'CHK-KUJ')->value('id');
    foreach (range(1, 8) as $n) {
        Rows::facility(['code' => sprintf('FX-6%03d', $n), 'name' => "Reconcile facility {$n}", 'lga_id' => $chikun, 'ward_id' => $kujama]);
    }
    configureSources(pageSize: 2);
    $this->odk = new FakeSubmissionSource;
    $this->kobo = new FakeKoboSource;
    useSource('odk', $this->odk);
    useSource('kobo', $this->kobo);
});

/** Kobo row $n: facility FX-6nnn, _id 8000+n, submitted $n minutes after 11:00 UTC on 15 April 2026. */
function rcKobo(int $n): array
{
    $row = json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/kobo/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
    $row['_id'] = 8000 + $n;
    $row['_uuid'] = "rc-kobo-{$n}";
    $row['meta/instanceID'] = $row['meta/rootUuid'] = "uuid:rc-kobo-{$n}";
    $row['_submission_time'] = CarbonImmutable::parse('2026-04-15T11:00:00Z')->addMinutes($n)->format('Y-m-d\TH:i:s');
    $row['deviceid'] = "collect:rckobo{$n}";
    $row['assessor_name'] = "Reconcile assessor {$n}";
    $row['facility/facility_code'] = sprintf('FX-6%03d', $n);

    return $row;
}

/** Kobo's edit of a row: the same _id and _submission_time, a new instance ID that deprecates the last. */
function rcEdit(array $row): array
{
    $row['meta/deprecatedID'] = $row['meta/instanceID'];
    $row['meta/instanceID'] .= '-v2';
    $row['consistency/consist_m2_register_present'] = 'no';

    return $row;
}

function rcReconcile(string $source = 'kobo'): PullRun
{
    return app(ReconcileSource::class)->execute($source)->refresh();
}

function rcHeld(int $n): Submission
{
    return Submission::query()->where('instance_id', "uuid:rc-kobo-{$n}")->sole();
}

it('catches an edit on Kobo that kept its _submission_time, which the pull cannot see', function (): void {
    $this->kobo->add(rcKobo(1), rcKobo(2), rcKobo(3));
    app(PullSubmissions::class)->execute('kobo');
    $assessment = Assessment::query()->where('submission_id', rcHeld(1)->id)->sole();
    $score = fn (): float => (float) AssessmentScore::query()->where('assessment_id', $assessment->id)->where('dimension', 'consistency')->where('month_slot', 2)->value('score');
    expect($score())->toBe(75.0);

    $this->kobo->add(rcEdit(rcKobo(1)));
    $pull = app(PullSubmissions::class)->execute('kobo')->refresh();
    expect([$pull->fetched, $pull->parsed])->toBe([1, 0]); // only the last row, at the cursor: the edit is missed

    $run = rcReconcile();

    $edit = Submission::query()->where('instance_id', 'uuid:rc-kobo-1-v2')->sole();
    expect([$run->trigger_type, $run->outcome, $run->source_id])->toBe([PullTrigger::Reconcile, PullOutcome::Succeeded, Rows::sourceId('kobo')])
        ->and([$run->fetched, $run->parsed, $run->accepted, $run->deletions_detected])->toBe([3, 1, 1, 0])
        ->and(rcHeld(1)->status)->toBe(SubmissionStatus::Superseded)
        ->and([$edit->external_id, $edit->deprecated_id])->toBe(['8001', 'uuid:rc-kobo-1'])
        ->and($assessment->refresh()->submission_id)->toBe($edit->id) // updated in place
        ->and($score())->toBe(50.0)
        ->and(SourceSync::query()->where('source_id', Rows::sourceId('kobo'))->sole()->last_reconciled_at)->not->toBeNull();

    // Run again: nothing new.
    $again = rcReconcile();
    expect([$again->fetched, $again->parsed, $again->duplicates])->toBe([3, 0, 0]);
});

it('flags a submission deleted at source and changes nothing else', function (): void {
    Event::fake([SourceDeletionDetected::class]);
    $this->kobo->add(rcKobo(1), rcKobo(2), rcKobo(3));
    app(PullSubmissions::class)->execute('kobo');
    $held = rcHeld(2);
    $assessment = Assessment::query()->where('submission_id', $held->id)->sole();
    $snapshot = [$held->status, $held->payload, $assessment->refresh()->toArray()];

    $this->kobo->remove(8002);
    $run = rcReconcile();

    $held->refresh();
    expect($run->deletions_detected)->toBe(1)
        ->and($held->deleted_at_source)->not->toBeNull()
        ->and([$held->status, $held->payload, $assessment->refresh()->toArray()])->toBe($snapshot)
        ->and(rcHeld(1)->deleted_at_source)->toBeNull()
        ->and(Activity::query()->where('subject_type', $held->getMorphClass())->where('subject_id', $held->id)->where('event', 'deleted_at_source')->count())->toBe(1);
    Event::assertDispatchedTimes(SourceDeletionDetected::class, 1);
    Event::assertDispatched(SourceDeletionDetected::class, fn (SourceDeletionDetected $e): bool => $e->submissionId === $held->id && $e->source === 'kobo' && $e->externalId === '8002');

    // Flagged once: the next sweep finds it gone again and says nothing new.
    expect(rcReconcile()->deletions_detected)->toBe(0);
    Event::assertDispatchedTimes(SourceDeletionDetected::class, 1);
});

it('treats an edited submission as present, by its logical ID, never as a deletion', function (): void {
    Event::fake([SourceDeletionDetected::class]);
    $this->kobo->add(rcKobo(1), rcKobo(2));
    app(PullSubmissions::class)->execute('kobo');
    $this->kobo->add(rcEdit(rcKobo(1)));

    $run = rcReconcile();

    expect([$run->parsed, $run->deletions_detected])->toBe([1, 0]);
    Event::assertNotDispatched(SourceDeletionDetected::class);
});

it('picks up a new submission the pull missed', function (): void {
    $this->kobo->add(rcKobo(2), rcKobo(3));
    app(PullSubmissions::class)->execute('kobo');
    $this->kobo->add(rcKobo(1)); // arrived late at the server with an earlier _submission_time

    $run = rcReconcile();

    expect([$run->parsed, $run->accepted])->toBe([1, 1])
        ->and(rcHeld(1)->status)->toBe(SubmissionStatus::Accepted);
});

it('compares only the open round\'s window: an older submission gone at source is not flagged', function (): void {
    Event::fake([SourceDeletionDetected::class]);
    $old = rcKobo(1);
    $old['_submission_time'] = '2026-02-10T09:00:00';
    $old['start'] = '2026-02-10T08:00:00.000+01:00';
    $old['end'] = '2026-02-10T08:45:00.000+01:00';
    $old['round_quarter'] = '1';
    $this->kobo->add($old, rcKobo(2));
    app(BackfillSubmissions::class)->execute('kobo');
    $this->kobo->remove(8001);

    $run = rcReconcile();

    $window = Round::query()->where('status', 'open')->sole()->window_start;
    expect($run->deletions_detected)->toBe(0)
        ->and(rcHeld(1)->deleted_at_source)->toBeNull()
        ->and($this->kobo->calls[array_key_last($this->kobo->calls)]['since'])->toBe(CarbonImmutable::parse($window->toDateString(), (string) config('app.timezone'))->utc()->format('Y-m-d\TH:i:s'));
    Event::assertNotDispatched(SourceDeletionDetected::class);
});

it('flags nothing when the sweep could not read the whole window', function (): void {
    Event::fake([SourceDeletionDetected::class]);
    $this->kobo->add(rcKobo(1), rcKobo(2), rcKobo(3), rcKobo(4));
    app(PullSubmissions::class)->execute('kobo');
    $this->kobo->remove(8001);
    // Three rows left: two pages of two; the second fails.
    $second = count($this->kobo->calls) + 2;
    $this->kobo->failOn = fn (int $call): ?Throwable => $call === $second ? new RuntimeException('Kobo went away') : null;

    expect(fn () => rcReconcile())->toThrow(RuntimeException::class);

    expect(rcHeld(1)->deleted_at_source)->toBeNull()
        ->and(PullRun::query()->where('trigger_type', PullTrigger::Reconcile)->sole()->outcome)->toBe(PullOutcome::Failed);
    Event::assertNotDispatched(SourceDeletionDetected::class);
});

it('reconciles nothing when no round is open', function (): void {
    Round::query()->where('status', 'open')->update(['status' => 'closed', 'rule_version_id' => DB::table('scoring_rule_versions')->value('id')]);
    $this->kobo->add(rcKobo(1));

    $run = rcReconcile();

    expect([$run->outcome, $run->fetched])->toBe([PullOutcome::Succeeded, 0])
        ->and($this->kobo->calls)->toBe([]);
});

it('flags a deletion on ODK Central the same way', function (): void {
    Event::fake([SourceDeletionDetected::class]);
    $entry = json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/odk/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
    $entry['facility']['facility_code'] = 'FX-6005';
    $this->odk->add($entry);
    app(PullSubmissions::class)->execute('odk');
    $this->odk->rows = [];
    // FixtureWorld's own ODK submissions of the window are not on this fake Central either.
    $window = ReconcileSource::windowStart();
    $held = Submission::query()->where('source_id', Rows::sourceId('odk'))->where('submitted_at', '>=', $window)->where('status', '<>', SubmissionStatus::Superseded)->count();

    $run = rcReconcile('odk');

    expect([$run->source_id, $run->deletions_detected])->toBe([Rows::sourceId('odk'), $held])
        ->and($held)->toBeGreaterThanOrEqual(1)
        ->and(Submission::query()->where('instance_id', 'uuid:fx-clean-visit')->sole()->deleted_at_source)->not->toBeNull();
    Event::assertDispatched(SourceDeletionDetected::class, fn (SourceDeletionDetected $e): bool => $e->source === 'odk' && $e->externalId === 'uuid:fx-clean-visit');
});

it('runs under the source\'s pull lock, and goes back on the queue while a pull of that source runs', function (): void {
    config(['edqa.pull.lock_wait_seconds' => 0]);
    $held = Cache::lock(PullLock::name('kobo'), 900);
    expect($held->get())->toBeTrue();

    $job = (new ReconcileSourceJob('kobo'))->withFakeQueueInteractions();
    $job->handle(app(PullLock::class), app(ReconcileSource::class));

    $job->assertReleased(60);
    $held->release();
    $this->artisan('edqa:reconcile', ['source' => 'kobo'])->assertSuccessful();
    expect(PullRun::query()->where('trigger_type', PullTrigger::Reconcile)->count())->toBe(1);
});

it('schedules a pull every ten minutes and a nightly reconcile for each configured source, each apart', function (): void {
    $events = function (): array {
        app()->forgetInstance(Schedule::class);
        ScheduleFacade::clearResolvedInstance();
        require base_path('routes/console.php');

        return array_values(array_filter(ScheduleFacade::getFacadeRoot()->events(), fn ($e): bool => str_starts_with((string) $e->description, 'edqa:')));
    };

    $scheduled = $events();
    $by = fn (string $name): array => array_values(array_filter($scheduled, fn ($e): bool => $e->description === $name));
    expect(array_map(fn ($e): string => (string) $e->description, $scheduled))->toEqualCanonicalizing(['edqa:pull:odk', 'edqa:pull:kobo', 'edqa:reconcile:odk', 'edqa:reconcile:kobo'])
        ->and($by('edqa:pull:kobo')[0]->expression)->toBe('*/10 * * * *')
        ->and($by('edqa:pull:kobo')[0]->withoutOverlapping)->toBeTrue()
        ->and($by('edqa:reconcile:kobo')[0]->expression)->toBe('30 2 * * *')
        ->and($by('edqa:pull:odk')[0]->mutexName())->not->toBe($by('edqa:pull:kobo')[0]->mutexName());

    // A source switched off (env or the administrator's switch) is still scheduled, and skipped when due.
    DB::table('submission_sources')->where('key', 'kobo')->update(['is_enabled' => false]);
    expect($by('edqa:pull:kobo')[0]->filtersPass(app()))->toBeFalse()
        ->and($by('edqa:pull:odk')[0]->filtersPass(app()))->toBeTrue();

    config(['edqa.sources.kobo.url' => null]);
    expect(array_map(fn ($e): string => (string) $e->description, $events()))->toEqualCanonicalizing(['edqa:pull:odk', 'edqa:reconcile:odk']);
});
