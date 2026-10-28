<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Events\PullFailed;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Odk\PullLock;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSubmissionSource;
use Tests\Support\FixtureWorld;
use Tests\Support\Rows;

// The ODK pull (ARCHITECTURE.md §5.3): resumable, idempotent, one page per transaction, the cursor
// last. The source is an in-memory Central (FakeSubmissionSource); OdkMockTest runs the same pull
// over HTTP against the mock. Entries are tests/Fixtures/sources/odk/valid/clean-visit.json, one facility
// and one assessor each, submitted a minute apart.

beforeEach(function (): void {
    FixtureWorld::build();
    CurrentRuleVersion::forget();
    $chikun = (int) DB::table('lgas')->where('code', 'CHK')->value('id');
    $kujama = (int) DB::table('wards')->where('code', 'CHK-KUJ')->value('id');
    foreach (range(1, 20) as $n) {
        Rows::facility(['code' => sprintf('FX-2%03d', $n), 'name' => "Pull facility {$n}", 'lga_id' => $chikun, 'ward_id' => $kujama]);
    }
    config(['edqa.sources.odk' => [...config('edqa.sources.odk'),
        'url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'secret-for-tests', 'project_id' => '7', 'form_id' => 'dqa', 'page_size' => 3,
    ]]);
    config(['edqa.heartbeats.odk_pull' => null]);
    $this->source = new FakeSubmissionSource;
    app()->instance(SubmissionSource::class, $this->source);
});

/**
 * Entry $n: facility FX-2nnn, its own assessor, submitted $n minutes after 10:00 on 15 April 2026.
 * 'broken' names an unknown facility (quarantined); 'short' is a 10-minute visit (flagged).
 *
 * @return array<string, mixed>
 */
function pullEntry(int $n, string $kind = 'valid'): array
{
    $entry = json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/odk/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
    $entry['__id'] = $entry['meta']['instanceID'] = "uuid:pull-{$n}";
    $entry['__system']['submissionDate'] = CarbonImmutable::parse('2026-04-15T10:00:00.000Z')->addMinutes($n)->format('Y-m-d\TH:i:s.v\Z');
    $entry['__system']['deviceId'] = "collect:pull{$n}";
    $entry['assessor_name'] = "Assessor {$n}";
    $entry['facility']['facility_code'] = $kind === 'broken' ? "NOPE-{$n}" : sprintf('FX-2%03d', $n);
    if ($kind === 'short') {
        $entry['end'] = '2026-04-14T09:10:00.000+01:00';
    }

    return $entry;
}

/** The ODK edit of entry $n (same __id and submissionDate, a new instance ID), as Central serves it. */
function pullEdit(array $entry, string $updatedAt = '2026-04-20T08:00:00.000Z'): array
{
    $entry['meta'] = ['instanceID' => $entry['meta']['instanceID'].'-v2', 'deprecatedID' => $entry['meta']['instanceID']];
    $entry['__system']['updatedAt'] = $updatedAt;
    $entry['__system']['edits'] = 1;

    return $entry;
}

function pullNow(PullTrigger $trigger = PullTrigger::Scheduled, ?int $userId = null): PullRun
{
    return app(PullSubmissions::class)->execute($trigger, $userId)->refresh();
}

function pullSync(): SourceSync
{
    return SourceSync::query()->where('form_ref', '7/dqa')->sole();
}

/** Assessments made from pulled submissions (FixtureWorld's own have submissions of their own). */
function pulledAssessments(): int
{
    return Assessment::query()->whereIn('submission_id', pulled()->select('id'))->count();
}

/** @return Builder<Submission> the submissions this file's pulls stored */
function pulled(): Builder
{
    return Submission::query()->where('instance_id', 'like', 'uuid:pull-%');
}

/** Rows in every table of the public schema. @return array<string, int> */
function pullTableCounts(): array
{
    $counts = [];
    foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $table) {
        $counts[$table->tablename] = DB::table($table->tablename)->count();
    }

    return $counts;
}

it('pulls three pages: every submission stored once, processed, counted, the cursor at the newest', function (): void {
    $this->source->add(pullEntry(1), pullEntry(2), pullEntry(3, 'broken'), pullEntry(4), pullEntry(5, 'short'), pullEntry(6), pullEntry(7));

    $run = pullNow();

    expect($run->outcome)->toBe(PullOutcome::Succeeded)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->toBeNull()
        ->and([$run->fetched, $run->duplicates, $run->accepted, $run->flagged, $run->quarantined])->toBe([7, 0, 5, 1, 1])
        ->and(pulled()->count())->toBe(7)
        ->and(pulledAssessments())->toBe(6)
        ->and(QuarantinedRecord::query()->sole()->instance_id)->toBe('uuid:pull-3')
        ->and(Submission::query()->where('instance_id', 'uuid:pull-5')->value('status'))->toBe(SubmissionStatus::Flagged)
        ->and(pullSync()->cursor_at?->equalTo(CarbonImmutable::parse('2026-04-15T10:07:00Z')))->toBeTrue()
        ->and(pullSync()->submission_count)->toBe(7)
        ->and(pullSync()->last_error)->toBeNull();

    // Pass 1: three pages, each from the newest timestamp so far (ge), then pass 2 for edits.
    $passOne = array_values(array_filter($this->source->calls, fn (array $c): bool => $c['by'] === 'submissionDate'));
    expect(array_column($passOne, 'since'))->toBe([null, '2026-04-15T10:03:00.000Z', '2026-04-15T10:05:00.000Z'])
        ->and(array_column($passOne, 'inclusive'))->toBe([false, true, true])
        ->and(collect($this->source->calls)->where('by', 'changedSince')->count())->toBe(1);
});

it('stores the raw entry verbatim', function (): void {
    $this->source->add($entry = pullEntry(1));

    pullNow();

    expect(pulled()->sole()->payload)->toEqual($entry)
        ->and(pulled()->sole()->form_version)->toBe('2026.1')
        ->and(pulled()->sole()->form_ref)->toBe('dqa');
});

it('replays a page that crashed, with no duplicates and the cursor where the last good page left it', function (): void {
    $this->source->add(...array_map(fn (int $n): array => pullEntry($n), range(1, 7)));
    $crash = true;
    Assessment::creating(function (Assessment $assessment) use (&$crash): void {
        $code = DB::table('facilities')->where('id', $assessment->facility_id)->value('code');
        if ($crash && $code === 'FX-2005') {
            throw new RuntimeException('Simulated crash in page 2');
        }
    });

    expect(fn () => pullNow())->toThrow(RuntimeException::class, 'Simulated crash in page 2');

    $failed = PullRun::query()->sole();
    expect($failed->outcome)->toBe(PullOutcome::Failed)
        ->and($failed->error)->toContain('Simulated crash in page 2')
        ->and([$failed->fetched, $failed->accepted])->toBe([3, 3])
        ->and(pulled()->pluck('instance_id')->sort()->values()->all())->toBe(['uuid:pull-1', 'uuid:pull-2', 'uuid:pull-3'])
        ->and(pullSync()->cursor_at?->equalTo(CarbonImmutable::parse('2026-04-15T10:03:00Z')))->toBeTrue();

    $crash = false;
    $run = pullNow();

    expect($run->outcome)->toBe(PullOutcome::Succeeded)
        ->and([$run->fetched, $run->duplicates, $run->accepted])->toBe([5, 1, 4]) // entry 3 again (ge), then 4–7
        ->and(pulled()->count())->toBe(7)
        ->and(pulled()->distinct()->count('instance_id'))->toBe(7)
        ->and(pulledAssessments())->toBe(7)
        ->and(pullSync()->cursor_at?->equalTo(CarbonImmutable::parse('2026-04-15T10:07:00Z')))->toBeTrue();
});

it('leaves nothing of a page that crashed half way: one transaction', function (): void {
    $this->source->add(pullEntry(1), pullEntry(2), pullEntry(3));
    Assessment::creating(function (Assessment $assessment): void {
        if (DB::table('facilities')->where('id', $assessment->facility_id)->value('code') === 'FX-2002') {
            throw new RuntimeException('Simulated crash mid-page');
        }
    });
    $before = pullTableCounts();

    expect(fn () => pullNow())->toThrow(RuntimeException::class);

    $after = pullTableCounts();
    expect(pulled()->count())->toBe(0)
        ->and(DB::table('item_responses')->count())->toBe($before['item_responses'])
        ->and(AssessmentScore::query()->count())->toBe($before['assessment_scores'])
        ->and($after['assessments'])->toBe($before['assessments'])
        ->and(pullSync()->cursor_at)->toBeNull()
        ->and(pullSync()->submission_count)->toBe(0)
        ->and(PullRun::query()->sole()->accepted)->toBe(0);
});

it('inserts nothing when the same pull runs again', function (): void {
    $this->source->add(pullEntry(1), pullEntry(2), pullEntry(3), pullEntry(4));
    pullNow();
    $before = pullTableCounts();

    $again = pullNow();

    expect($again->outcome)->toBe(PullOutcome::Succeeded)
        ->and([$again->fetched, $again->duplicates, $again->accepted, $again->flagged, $again->quarantined])->toBe([1, 1, 0, 0, 0])
        ->and(Arr::except(pullTableCounts(), ['pull_runs', 'activity_log']))->toBe(Arr::except($before, ['pull_runs', 'activity_log']));
});

it('never loses rows that share the cursor timestamp, even a whole page of them', function (): void {
    // Five submissions in the same millisecond, page size 3: the cursor cannot move inside them.
    $entries = array_map(function (int $n): array {
        $entry = pullEntry($n);
        $entry['__system']['submissionDate'] = '2026-04-15T10:00:00.000Z';

        return $entry;
    }, range(1, 5));
    $this->source->add(...$entries);
    $this->source->add(pullEntry(6));

    $run = pullNow();

    expect(pulled()->count())->toBe(6)
        ->and([$run->fetched, $run->duplicates])->toBe([6, 0]);
});

it('applies an ODK edit: the original is superseded and its assessment updated in place, audited', function (): void {
    $this->source->add($original = pullEntry(1), pullEntry(2));
    pullNow();
    $assessment = Assessment::query()->whereHas('submission', fn ($q) => $q->where('instance_id', 'uuid:pull-1'))->sole();
    $before = AssessmentScore::query()->where('assessment_id', $assessment->id)->where('dimension', 'availability')->where('month_slot', 1)->value('score');

    $edit = pullEdit($original);
    $edit['availability']['avail_m1_nhmis_summary'] = 'no'; // 3 of 4 passed becomes 2 of 4
    $this->source->add($edit);
    $run = pullNow();

    $old = Submission::query()->where('instance_id', 'uuid:pull-1')->sole();
    $new = Submission::query()->where('instance_id', 'uuid:pull-1-v2')->sole();
    expect([$run->fetched, $run->accepted])->toBe([2, 1]) // entry 2 again (ge), and the edit
        ->and($old->status)->toBe(SubmissionStatus::Superseded)
        ->and($old->superseded_by_id)->toBe($new->id)
        ->and($new->deprecated_id)->toBe('uuid:pull-1')
        ->and(pulledAssessments())->toBe(2)
        ->and($assessment->refresh()->submission_id)->toBe($new->id)
        ->and((float) $before)->toBe(75.0)
        ->and((float) AssessmentScore::query()->where('assessment_id', $assessment->id)->where('dimension', Dimension::Availability->value)->where('month_slot', 1)->value('score'))->toBe(50.0)
        ->and(Activity::query()->forSubject($assessment)->where('event', 'updated')->exists())->toBeTrue()
        ->and(pullSync()->edit_cursor_at?->equalTo(CarbonImmutable::parse('2026-04-20T08:00:00Z')))->toBeTrue();

    // The edit is not applied twice.
    $again = pullNow();
    expect([$again->accepted, $again->duplicates])->toBe([0, 2]);
});

it('catches an edit that keeps the original submissionDate on the next pull, by its updatedAt', function (): void {
    $this->source->add($original = pullEntry(1));
    pullNow();

    $edit = pullEdit($original, '2026-04-21T09:30:00.000Z');
    expect($edit['__system']['submissionDate'])->toBe($original['__system']['submissionDate']); // before the cursor
    $this->source->add($edit);
    pullNow();

    $new = Submission::query()->where('instance_id', 'uuid:pull-1-v2')->sole();
    expect($new->source_updated_at?->equalTo(CarbonImmutable::parse('2026-04-21T09:30:00Z')))->toBeTrue()
        ->and($new->external_id)->toBe('uuid:pull-1')
        ->and(Submission::query()->where('instance_id', 'uuid:pull-1')->sole()->status)->toBe(SubmissionStatus::Superseded)
        // Both pulls asked for every edit (no edit seen yet); the second found it.
        ->and(collect($this->source->calls)->where('by', 'changedSince')->pluck('since')->all())->toBe([null, null]);

    // From then on the edits pass starts at that edit.
    pullNow();
    expect(collect($this->source->calls)->where('by', 'changedSince')->pluck('since')->last())->toBe('2026-04-21T09:30:00.000Z');
});

it('treats a known external ID with a new instance ID as an edit even without deprecatedID', function (): void {
    $this->source->add($original = pullEntry(1));
    pullNow();
    $assessment = Assessment::query()->whereHas('submission', fn ($q) => $q->where('instance_id', 'uuid:pull-1'))->sole();

    $edit = pullEdit($original);
    unset($edit['meta']['deprecatedID']); // a source (or an older Central) that does not send it
    $edit['availability']['avail_m1_nhmis_summary'] = 'no';
    $this->source->add($edit);
    pullNow();

    $old = Submission::query()->where('instance_id', 'uuid:pull-1')->sole();
    $new = Submission::query()->where('instance_id', 'uuid:pull-1-v2')->sole();
    expect($new->deprecated_id)->toBe('uuid:pull-1') // inferred; the payload is as sent
        ->and($new->payload['meta'])->toBe(['instanceID' => 'uuid:pull-1-v2'])
        ->and($old->status)->toBe(SubmissionStatus::Superseded)
        ->and($old->superseded_by_id)->toBe($new->id)
        ->and($assessment->refresh()->submission_id)->toBe($new->id)
        ->and(pulledAssessments())->toBe(1)
        ->and((float) AssessmentScore::query()->where('assessment_id', $assessment->id)->where('dimension', Dimension::Availability->value)->where('month_slot', 1)->value('score'))->toBe(50.0);
});

it('does not take a new submission for an edit: another external ID, or the same instance again', function (): void {
    $this->source->add(pullEntry(1));
    pullNow();
    $this->source->add(pullEntry(2));
    pullNow();

    expect(Submission::query()->whereNotNull('deprecated_id')->count())->toBe(0)
        ->and(Submission::query()->where('status', SubmissionStatus::Superseded)->count())->toBe(0)
        ->and(pulledAssessments())->toBe(2);
});

it('resolves the quarantined original when its edit arrives (fix at source)', function (): void {
    $this->source->add($broken = pullEntry(1, 'broken'));
    pullNow();
    $record = QuarantinedRecord::query()->sole();

    $edit = pullEdit($broken);
    $edit['facility']['facility_code'] = 'FX-2001';
    $this->source->add($edit);
    $run = pullNow();

    expect($run->accepted)->toBe(1)
        ->and($record->refresh()->status)->toBe(QuarantineStatus::Resolved)
        ->and($record->resolution)->toBe(QuarantineResolution::Superseded)
        ->and(Submission::query()->where('instance_id', 'uuid:pull-1')->value('status'))->toBe(SubmissionStatus::Superseded)
        ->and(Assessment::query()->whereHas('submission', fn ($q) => $q->where('instance_id', 'uuid:pull-1-v2'))->exists())->toBeTrue();
});

it('counts the run row exactly, and audits a manual pull with its administrator', function (): void {
    $admin = User::factory()->withTwoFactor()->create();
    $this->source->add(pullEntry(1), pullEntry(2, 'broken'), pullEntry(3, 'short'), pullEntry(4));

    $run = pullNow(PullTrigger::Manual, $admin->id);

    expect($run->trigger_type)->toBe(PullTrigger::Manual)
        ->and($run->triggered_by)->toBe($admin->id)
        ->and($run->only(['fetched', 'duplicates', 'accepted', 'flagged', 'quarantined']))->toBe(['fetched' => 4, 'duplicates' => 0, 'accepted' => 2, 'flagged' => 1, 'quarantined' => 1]);
    $entry = Activity::query()->forSubject($run)->where('event', 'pull_requested')->sole();
    expect($entry->causer?->is($admin))->toBeTrue();
    expect(Activity::query()->where('subject_type', $run->getMorphClass())->where('event', 'pull_requested')->count())->toBe(1);
    pullNow();
    expect(Activity::query()->where('event', 'pull_requested')->count())->toBe(1); // scheduled pulls are not
});

it('records a pull without credentials as skipped, not failed, and asks the source nothing', function (): void {
    config(['edqa.sources.odk.url' => null]);
    $this->source->add(pullEntry(1));

    $run = pullNow();

    expect($run->outcome)->toBe(PullOutcome::SkippedNotConfigured)
        ->and($run->error)->toContain('ODK_CENTRAL_URL')
        ->and($this->source->calls)->toBe([])
        ->and(pulled()->count())->toBe(0)
        ->and(SourceSync::query()->count())->toBe(0);
});

it('pings the heartbeat after a successful pull only', function (): void {
    config(['edqa.heartbeats.odk_pull' => 'https://hc.example.org/ping/odk']);
    Http::fake(['hc.example.org/*' => Http::response('OK')]);
    $this->source->add(pullEntry(1));

    pullNow();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://hc.example.org/ping/odk');

    $this->source->failOn = fn (int $call): ?Throwable => new RuntimeException('Central is down');
    expect(fn () => pullNow())->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});

it('skips the heartbeat when none is configured, and never fails the pull over it', function (): void {
    Http::fake(fn () => throw new ConnectionException('unreachable'));
    $this->source->add(pullEntry(1));
    pullNow();
    Http::assertNothingSent();

    config(['edqa.heartbeats.odk_pull' => 'https://hc.example.org/ping/odk']);

    expect(pullNow()->outcome)->toBe(PullOutcome::Succeeded);
});

it('lets one pull run at a time: a second waits, then goes back on the queue', function (): void {
    $this->source->add(pullEntry(1));
    config(['edqa.odk.pull.lock_wait_seconds' => 0]);
    $held = Cache::lock(PullLock::NAME, 900);
    expect($held->get())->toBeTrue();

    $job = (new PullSubmissionsJob)->withFakeQueueInteractions();
    $job->handle(app(PullLock::class), app(PullSubmissions::class));

    $job->assertReleased(60);
    expect(PullRun::query()->count())->toBe(0)
        ->and(pulled()->count())->toBe(0);
    $this->artisan('edqa:pull')->expectsOutputToContain(__('odk.pull.busy'))->assertFailed();

    $held->release();
    $next = (new PullSubmissionsJob)->withFakeQueueInteractions();
    $next->handle(app(PullLock::class), app(PullSubmissions::class));
    $next->assertNotReleased();
    expect(pulled()->count())->toBe(1)
        ->and(Cache::lock(PullLock::NAME, 1)->get())->toBeTrue(); // released after the pull
});

it('is queued on default with three tries and the agreed backoff', function (): void {
    $job = new PullSubmissionsJob(PullTrigger::Manual, 5);

    expect($job->queue)->toBe('default')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 120, 600])
        ->and($job->timeout)->toBe(900)
        ->and($job->trigger)->toBe(PullTrigger::Manual)
        ->and($job->userId)->toBe(5);
});

it('records the final failure on the sync row and raises PullFailed', function (): void {
    Event::fake([PullFailed::class]);
    $this->source->failOn = fn (int $call): ?Throwable => new RuntimeException('Central is down');

    expect(fn () => PullSubmissionsJob::dispatchSync())->toThrow(RuntimeException::class);
    $run = PullRun::query()->sole();
    (new PullSubmissionsJob)->failed(new RuntimeException('Central is down'));

    expect($run->outcome)->toBe(PullOutcome::Failed)
        ->and($run->error)->toBe('RuntimeException: Central is down')
        ->and(pullSync()->last_error)->toBe('RuntimeException: Central is down');
    Event::assertDispatched(PullFailed::class, fn (PullFailed $e): bool => $e->runId === $run->id && $e->message === 'RuntimeException: Central is down');

    // The next good pull clears it.
    $this->source->failOn = null;
    pullNow();
    expect(pullSync()->last_error)->toBeNull();
});

it('runs a bounded number of queries per page', function (): void {
    config(['edqa.sources.odk.page_size' => 50]);
    $this->source->add(...array_map(fn (int $n): array => pullEntry($n), range(1, 5)));
    DB::enableQueryLog();
    pullNow();
    $five = count(DB::getQueryLog());

    $this->source->add(...array_map(fn (int $n): array => pullEntry($n), range(6, 15)));
    DB::flushQueryLog();
    pullNow();
    $ten = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Linear in the submissions, with a fixed cost per page (the context, one insert, the cursor):
    // about 21 per page and 31 per submission today (its assessment, items, nine audited scores).
    $perSubmission = ($ten - $five) / 5;
    $perPage = $five - 5 * $perSubmission;
    expect($perSubmission)->toBeLessThanOrEqual(32.0)
        ->and($perPage)->toBeLessThanOrEqual(25.0);
});

it('dry-runs: fetches, parses and validates, prints outcomes by rule code, and writes nothing', function (): void {
    $this->source->add(pullEntry(1), pullEntry(2));
    pullNow();
    $this->source->add(pullEntry(3), pullEntry(4, 'broken'), pullEntry(5, 'short'), pullEntry(6, 'broken'));
    $before = pullTableCounts();
    $sync = DB::table('source_syncs')->get()->all();

    $this->artisan('edqa:pull', ['--dry-run' => true, '--limit' => 200])
        ->expectsOutputToContain(__('odk.pull.dry_run_title'))
        ->expectsOutputToContain('Fetched 5; 1 already stored; of the rest 1 would be accepted, 1 flagged and 2 quarantined.')
        ->expectsTable(['Rule', 'Severity', 'Submissions'], [['FACILITY_UNKNOWN', 'hard', 2], ['VISIT_TOO_SHORT', 'soft', 1]])
        ->assertSuccessful();

    expect(pullTableCounts())->toBe($before)
        ->and(DB::table('source_syncs')->get()->all())->toEqual($sync);
});

it('dry-runs at most --limit submissions', function (): void {
    $this->source->add(...array_map(fn (int $n): array => pullEntry($n), range(1, 7)));

    $this->artisan('edqa:pull', ['--dry-run' => true, '--limit' => 4])
        ->expectsOutputToContain('Fetched 4; 0 already stored; of the rest 4 would be accepted')
        ->assertSuccessful();
    expect(pulled()->count())->toBe(0)
        ->and(PullRun::query()->count())->toBe(0);
});

it('pulls synchronously from the console, as a manual pull', function (): void {
    $this->source->add(pullEntry(1), pullEntry(2, 'broken'));

    $this->artisan('edqa:pull')
        ->expectsOutputToContain('Pull succeeded: fetched 2, 0 already stored, 1 accepted, 0 flagged, 1 quarantined.')
        ->assertSuccessful();
    expect(PullRun::query()->sole()->trigger_type)->toBe(PullTrigger::Manual);
});

it('schedules the pull every ten minutes only when ODK is configured', function (): void {
    $pulls = function (): array {
        app()->forgetInstance(Schedule::class);
        ScheduleFacade::clearResolvedInstance();
        require base_path('routes/console.php');

        return array_values(array_filter(ScheduleFacade::getFacadeRoot()->events(), fn ($event): bool => str_contains((string) $event->description, 'PullSubmissionsJob')));
    };

    $scheduled = $pulls();
    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]->expression)->toBe('*/10 * * * *')
        ->and($scheduled[0]->withoutOverlapping)->toBeTrue();

    config(['edqa.sources.odk.url' => null]);
    expect($pulls())->toBe([]);
});

it('never puts SQL or payload values in the error administrators see', function (): void {
    $this->source->add(pullEntry(1));
    Assessment::creating(function (): void {
        throw new QueryException('pgsql', 'insert into assessments (assessor_name) values (?)', ['Assessor 1 secret'], new PDOException('SQLSTATE[23514]: Check violation'));
    });

    expect(fn () => pullNow())->toThrow(QueryException::class);

    $error = (string) PullRun::query()->sole()->error;
    expect($error)->toStartWith('QueryException: SQLSTATE')
        ->and($error)->not->toContain('insert into')
        ->and($error)->not->toContain('Assessor 1');
});
