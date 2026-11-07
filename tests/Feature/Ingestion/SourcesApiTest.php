<?php

declare(strict_types=1);

use App\Domain\Ingestion\Aggregators\SourceStatusAggregator;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\KoboUnreachable;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\Jobs\ReconcileSourceJob;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Queries\SourceStatusQuery;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeKoboSource;
use Tests\Support\FakeSubmissionSource;
use Tests\Support\Rows;

// The sources API (ARCHITECTURE.md §8): GET /api/sources, POST /api/sources/{key}/pull,
// POST /api/sources/pull-all, POST /api/sources/{key}/reconcile, GET /api/pulls/{runId}; and each
// source's sync state (SourceStatusAggregator) for the status bar.

beforeEach(function (): void {
    configureSources();
    $this->odk = new FakeSubmissionSource;
    $this->kobo = new FakeKoboSource;
    useSource('odk', $this->odk);
    useSource('kobo', $this->kobo);
    $this->admin = User::factory()->create();
});

// ---- POST /api/sources/{key}/pull

it('starts a manual pull of the named source, audited with the source, and answers 202 with its run id', function (string $key): void {
    Queue::fake();

    $response = $this->actingAs($this->admin)->postJson("/admin/api/sources/{$key}/pull")->assertStatus(202)->assertJson(['already_running' => false]);

    $run = PullRun::query()->sole();
    expect($response->json('run_id'))->toBe($run->id)
        ->and($run->source_id)->toBe(Rows::sourceId($key))
        ->and($run->trigger_type)->toBe(PullTrigger::Manual)
        ->and($run->triggered_by)->toBe($this->admin->id)
        ->and($run->outcome)->toBeNull();
    Queue::assertPushed(PullSubmissionsJob::class, 1);
    Queue::assertPushed(PullSubmissionsJob::class, fn (PullSubmissionsJob $job): bool => $job->source === $key && $job->trigger === PullTrigger::Manual && $job->userId === $this->admin->id && $job->runId === $run->id);
    $entry = Activity::query()->forSubject($run)->where('event', 'pull_requested')->sole();
    expect($entry->causer?->is($this->admin))->toBeTrue()
        ->and($entry->properties['attributes'])->toEqual(['trigger' => 'manual', 'source' => $key])
        ->and($entry->properties['joined_running'])->toBeFalse();
})->with(['odk', 'kobo']);

it('returns the source\'s running pull instead of queueing a second, and audits that too', function (): void {
    Queue::fake();
    $running = PullRun::query()->create(['source_id' => Rows::sourceId('kobo'), 'trigger_type' => PullTrigger::Scheduled, 'started_at' => now()->subMinute()]);

    $this->actingAs($this->admin)->postJson('/admin/api/sources/kobo/pull')->assertStatus(202)->assertJson(['run_id' => $running->id, 'already_running' => true]);

    expect(PullRun::query()->count())->toBe(1);
    Queue::assertNothingPushed();
    expect(Activity::query()->forSubject($running)->where('event', 'pull_requested')->sole()->properties['joined_running'])->toBeTrue();
});

it('does not count another source\'s pull, a backfill, a sweep or a run left open long ago as running', function (): void {
    Queue::fake();
    PullRun::query()->create(['source_id' => Rows::sourceId('kobo'), 'trigger_type' => PullTrigger::Scheduled, 'started_at' => now()->subMinute()]);
    PullRun::query()->create(['source_id' => Rows::sourceId('odk'), 'trigger_type' => PullTrigger::Backfill, 'started_at' => now()->subMinute()]);
    PullRun::query()->create(['source_id' => Rows::sourceId('odk'), 'trigger_type' => PullTrigger::Reconcile, 'started_at' => now()->subMinute()]);
    PullRun::query()->create(['source_id' => Rows::sourceId('odk'), 'trigger_type' => PullTrigger::Scheduled, 'started_at' => now()->subHours(3)]);

    $this->actingAs($this->admin)->postJson('/admin/api/sources/odk/pull')->assertStatus(202)->assertJson(['already_running' => false]);

    Queue::assertPushed(PullSubmissionsJob::class, fn (PullSubmissionsJob $job): bool => $job->source === 'odk');
});

it('carries the run it was given through the pull, without a second audit entry', function (): void {
    $this->odk->add(json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/odk/valid/clean-visit.json')), true));

    // The sync test queue runs the pull at once.
    $runId = $this->actingAs($this->admin)->postJson('/admin/api/sources/odk/pull')->assertStatus(202)->json('run_id');

    $run = PullRun::query()->sole();
    expect($run->id)->toBe($runId)
        ->and($run->outcome)->toBe(PullOutcome::Succeeded)
        ->and($run->fetched)->toBe(1)
        ->and(Activity::query()->where('event', 'pull_requested')->count())->toBe(1);
});

it('closes the run it was given when the pull never got the lock', function (): void {
    $run = PullRun::query()->create(['source_id' => Rows::sourceId('odk'), 'trigger_type' => PullTrigger::Manual, 'triggered_by' => $this->admin->id, 'started_at' => now()]);

    (new PullSubmissionsJob('odk', PullTrigger::Manual, $this->admin->id, $run->id))->failed(new RuntimeException('lock busy'));

    expect($run->refresh()->outcome)->toBe(PullOutcome::Failed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->toBe('RuntimeException: lock busy');
});

it('refuses a source not configured or switched off with 409, and queues nothing', function (Closure $setup, string $code): void {
    Queue::fake();
    $setup();

    $this->actingAs($this->admin)->postJson('/admin/api/sources/kobo/pull')->assertStatus(409)->assertJson(['code' => $code])
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'KoboToolbox'));
    $this->actingAs($this->admin)->postJson('/admin/api/sources/kobo/reconcile')->assertStatus(409)->assertJson(['code' => $code]);

    expect(PullRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'not configured' => [fn () => config(['edqa.sources.kobo.token' => null]), 'source_not_configured'],
    'off in env' => [fn () => config(['edqa.sources.kobo.enabled' => false]), 'source_disabled'],
    'off by an administrator' => [fn () => DB::table('submission_sources')->where('key', 'kobo')->update(['is_enabled' => false]), 'source_disabled'],
]);

it('answers 404 for a source not in config', function (string $key): void {
    Queue::fake();

    $this->actingAs($this->admin)->postJson("/admin/api/sources/{$key}/pull")->assertNotFound();
    $this->actingAs($this->admin)->postJson("/admin/api/sources/{$key}/reconcile")->assertNotFound();

    Queue::assertNothingPushed();
})->with(['dhis2', 'ODK', 'odk-central']);

it('limits "Pull now" to six a minute per source, each source on its own', function (): void {
    Queue::fake();
    foreach (range(1, 6) as $i) {
        $this->actingAs($this->admin)->postJson('/admin/api/sources/odk/pull')->assertStatus(202);
    }

    $this->actingAs($this->admin)->postJson('/admin/api/sources/odk/pull')->assertStatus(429);
    $this->actingAs($this->admin)->postJson('/admin/api/sources/kobo/pull')->assertStatus(202);
});

// ---- POST /api/sources/pull-all

it('pulls every configured and enabled source, one run each, audited', function (): void {
    Queue::fake();
    $running = PullRun::query()->create(['source_id' => Rows::sourceId('kobo'), 'trigger_type' => PullTrigger::Webhook, 'started_at' => now()]);

    $runs = $this->actingAs($this->admin)->postJson('/admin/api/sources/pull-all')->assertStatus(202)->json('runs');

    $odkRun = PullRun::query()->where('source_id', Rows::sourceId('odk'))->sole();
    expect($runs)->toBe([
        ['source' => 'odk', 'run_id' => $odkRun->id, 'already_running' => false],
        ['source' => 'kobo', 'run_id' => $running->id, 'already_running' => true],
    ]);
    Queue::assertPushed(PullSubmissionsJob::class, 1);
    expect(Activity::query()->where('event', 'pull_requested')->count())->toBe(2);
});

it('leaves out a source switched off, and refuses with 409 when none is on', function (): void {
    Queue::fake();
    config(['edqa.sources.kobo.enabled' => false]);

    expect($this->actingAs($this->admin)->postJson('/admin/api/sources/pull-all')->assertStatus(202)->json('runs.*.source'))->toBe(['odk']);

    config(['edqa.sources.odk.url' => null]);
    $this->actingAs($this->admin)->postJson('/admin/api/sources/pull-all')->assertStatus(409)->assertJson(['code' => 'no_source_enabled']);
});

// ---- POST /api/sources/{key}/reconcile

it('starts a reconcile sweep of the named source, audited, and carries the run through', function (): void {
    Queue::fake();

    $runId = $this->actingAs($this->admin)->postJson('/admin/api/sources/kobo/reconcile')->assertStatus(202)->assertJson(['already_running' => false])->json('run_id');

    $run = PullRun::query()->sole();
    expect($run->id)->toBe($runId)
        ->and($run->source_id)->toBe(Rows::sourceId('kobo'))
        ->and($run->trigger_type)->toBe(PullTrigger::Reconcile)
        ->and($run->triggered_by)->toBe($this->admin->id);
    Queue::assertPushed(ReconcileSourceJob::class, fn (ReconcileSourceJob $job): bool => $job->source === 'kobo' && $job->runId === $run->id && $job->userId === $this->admin->id);
    expect(Activity::query()->forSubject($run)->where('event', 'reconcile_requested')->sole()->properties['attributes'])->toEqual(['trigger' => 'reconcile', 'source' => 'kobo']);
});

it('runs the sweep on the run it was given', function (): void {
    Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);

    $runId = $this->actingAs($this->admin)->postJson('/admin/api/sources/kobo/reconcile')->assertStatus(202)->json('run_id');

    $run = PullRun::query()->sole();
    expect($run->id)->toBe($runId)
        ->and($run->outcome)->toBe(PullOutcome::Succeeded);
});

it('returns the running sweep, not a running pull, for a second reconcile', function (): void {
    Queue::fake();
    PullRun::query()->create(['source_id' => Rows::sourceId('odk'), 'trigger_type' => PullTrigger::Scheduled, 'started_at' => now()]);
    $sweep = PullRun::query()->create(['source_id' => Rows::sourceId('odk'), 'trigger_type' => PullTrigger::Reconcile, 'started_at' => now()]);

    $this->actingAs($this->admin)->postJson('/admin/api/sources/odk/reconcile')->assertStatus(202)->assertJson(['run_id' => $sweep->id, 'already_running' => true]);

    Queue::assertNothingPushed();
});

it('closes the sweep\'s run when it never got the lock', function (): void {
    $run = PullRun::query()->create(['source_id' => Rows::sourceId('kobo'), 'trigger_type' => PullTrigger::Reconcile, 'started_at' => now()]);

    (new ReconcileSourceJob('kobo', $this->admin->id, $run->id))->failed(new RuntimeException('lock busy'));

    expect($run->refresh()->outcome)->toBe(PullOutcome::Failed)
        ->and($run->error)->toBe('RuntimeException: lock busy');
});

// ---- GET /api/pulls/{runId}

it('shows a run\'s progress with its source, and 404 for one that does not exist', function (): void {
    $run = PullRun::query()->create(['source_id' => Rows::sourceId('kobo'), 'trigger_type' => PullTrigger::Manual, 'started_at' => now(), 'fetched' => 500, 'duplicates' => 3, 'cross_source_duplicates' => 2, 'accepted' => 480, 'flagged' => 7, 'quarantined' => 10]);

    $this->actingAs($this->admin)->getJson("/admin/api/pulls/{$run->id}")->assertOk()->assertExactJson(['data' => [
        'run_id' => $run->id, 'source' => 'kobo', 'trigger' => 'manual', 'status' => 'running', 'started_at' => $run->started_at->toIso8601String(), 'finished_at' => null,
        'fetched' => 500, 'parsed' => 0, 'duplicates' => 3, 'cross_source_duplicates' => 2, 'accepted' => 480, 'flagged' => 7, 'quarantined' => 10,
        'deletions_detected' => 0, 'error' => null,
    ]]);
    $run->update(['outcome' => PullOutcome::Failed, 'finished_at' => now(), 'error' => 'KoboUnreachable: KoboToolbox could not be reached']);
    $this->actingAs($this->admin)->getJson("/admin/api/pulls/{$run->id}")->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'KoboUnreachable: KoboToolbox could not be reached');

    $this->actingAs($this->admin)->getJson('/admin/api/pulls/999999')->assertNotFound();
});

// ---- GET /api/sources

it('reports every source: configured, enabled, reachable, its state, cursor, runs and open-round counts', function (): void {
    $q4 = Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
    $odkSubmission = Rows::submission(['source_id' => Rows::sourceId('odk')]);
    $koboSubmission = Rows::submission(['source_id' => Rows::sourceId('kobo')]);
    Rows::assessment(['round_id' => $q4, 'status' => 'accepted', 'submission_id' => $odkSubmission]);
    Rows::assessment(['round_id' => $q4, 'status' => 'flagged', 'submission_id' => $koboSubmission]);
    Rows::quarantined(['round_id' => $q4, 'submission_id' => $koboSubmission]);
    DB::table('source_syncs')->insert(['source_id' => Rows::sourceId('kobo'), 'form_ref' => 'aKdDqaForm2026', 'cursor_at' => '2026-10-20 09:00:00+01', 'backfill_checkpoint' => 30, 'submission_count' => 31, 'last_reconciled_at' => '2026-10-29 02:30:00+01', 'created_at' => now(), 'updated_at' => now()]);
    Rows::pullRun(['source_id' => Rows::sourceId('odk'), 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);
    $running = PullRun::query()->create(['source_id' => Rows::sourceId('kobo'), 'trigger_type' => PullTrigger::Scheduled, 'started_at' => now()]);

    $data = $this->actingAs($this->admin)->getJson('/admin/api/sources')->assertOk()->json('data');

    [$odk, $kobo] = $data['sources'];
    expect(array_column($data['sources'], 'key'))->toBe(['odk', 'kobo'])
        ->and($odk)->toMatchArray(['name' => 'ODK Central', 'type' => 'odk_central', 'configured' => true, 'problems' => [], 'enabled' => true, 'running_run_id' => null])
        ->and($odk['reachable'])->toMatchArray(['ok' => true, 'status' => 'ok'])
        ->and($odk['status'])->toMatchArray(['key' => 'odk', 'state' => 'healthy', 'minutesSinceSuccess' => 4])
        ->and($odk['last_run'])->toMatchArray(['fetched' => 0, 'outcome' => 'succeeded'])
        ->and($odk['open_rounds'])->toBe([['id' => $q4, 'year' => 2026, 'quarter' => 4, 'accepted' => 1, 'flagged' => 0, 'quarantined' => 0]])
        ->and($kobo)->toMatchArray(['name' => 'KoboToolbox', 'type' => 'kobo', 'running_run_id' => $running->id, 'last_run' => null])
        ->and($kobo['status']['state'])->toBe('not_connected')
        ->and($kobo['cursor'])->toMatchArray(['backfill_skip' => 30, 'submission_count' => 31])
        ->and($kobo['last_reconciled_at'])->toStartWith('2026-10-29T')
        ->and($kobo['open_rounds'])->toBe([['id' => $q4, 'year' => 2026, 'quarter' => 4, 'accepted' => 0, 'flagged' => 1, 'quarantined' => 1]])
        ->and($data['open_rounds'])->toBe([['id' => $q4, 'year' => 2026, 'quarter' => 4, 'accepted' => 1, 'flagged' => 1, 'quarantined' => 1]]);
});

it('asks each source whether it answers at most once a minute, each on its own', function (): void {
    $kobo = Mockery::mock(SubmissionSource::class);
    $kobo->shouldReceive('ping')->once()->andThrow(KoboUnreachable::connection(3));
    useSource('kobo', $kobo);

    $first = $this->actingAs($this->admin)->getJson('/admin/api/sources')->json('data.sources.1.reachable');
    $second = $this->actingAs($this->admin)->getJson('/admin/api/sources')->json('data.sources.1.reachable');

    expect($first)->toMatchArray(['ok' => false, 'status' => 'unreachable'])
        ->and($second)->toBe($first)
        ->and(Cache::has(SourceStatusQuery::cacheKey('kobo')))->toBeTrue()
        ->and(Cache::get(SourceStatusQuery::cacheKey('odk')))->toMatchArray(['ok' => true]);
});

it('names a source\'s missing settings, never their values, and asks it nothing', function (): void {
    config(['edqa.sources.kobo.url' => null]);
    $kobo = Mockery::mock(SubmissionSource::class);
    $kobo->shouldNotReceive('ping');
    useSource('kobo', $kobo);

    $response = $this->actingAs($this->admin)->getJson('/admin/api/sources')->assertOk();

    expect($response->json('data.sources.1.configured'))->toBeFalse()
        ->and($response->json('data.sources.1.problems'))->toContain('KOBO_BASE_URL')
        ->and($response->json('data.sources.1.reachable'))->toBeNull()
        ->and($response->json('data.sources.1.status.state'))->toBe('not_configured')
        ->and($response->getContent())->not->toContain('token-for-tests')->not->toContain('secret-for-tests');
});

it('keeps every sources endpoint behind the sign-in', function (string $method, string $uri): void {
    Queue::fake();

    $this->json($method, $uri)->assertUnauthorized();

    expect(PullRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([['GET', '/admin/api/sources'], ['POST', '/admin/api/sources/odk/pull'], ['POST', '/admin/api/sources/pull-all'], ['POST', '/admin/api/sources/kobo/reconcile'], ['GET', '/admin/api/pulls/1']]);

it('has no /api/odk endpoints any more', function (string $method, string $uri): void {
    $this->actingAs($this->admin)->json($method, $uri)->assertNotFound();
})->with([['POST', '/admin/api/odk/pull'], ['GET', '/admin/api/odk/pull/1'], ['GET', '/admin/api/odk/status']]);

// ---- Each source's state (the status bar)

it('gives each source its own state', function (Closure $setup, string $state, ?string $errorClass): void {
    $setup();

    $status = app(SourceStatusAggregator::class)->source('kobo');

    expect($status['state'])->toBe($state)
        ->and($status['lastErrorClass'])->toBe($errorClass);
})->with([
    'not configured' => [fn () => config(['edqa.sources.kobo.asset_uid' => null]), 'not_configured', null],
    'not configured, whatever ran before' => [function (): void {
        config(['edqa.sources.kobo.asset_uid' => null]);
        Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2)]);
    }, 'not_configured', null],
    'off in env' => [fn () => config(['edqa.sources.kobo.enabled' => false]), 'disabled', null],
    'off by an administrator, after a failure' => [function (): void {
        DB::table('submission_sources')->where('key', 'kobo')->update(['is_enabled' => false]);
        Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'failed', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2), 'error' => 'KoboUnreachable: x']);
    }, 'disabled', null],
    'configured, no pull yet' => [fn () => null, 'not_connected', null],
    'healthy' => [fn () => Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]), 'healthy', null],
    'stale, past 30 minutes' => [fn () => Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(46), 'finished_at' => now()->subMinutes(45)]), 'stale', null],
    'failed' => [function (): void {
        Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'succeeded', 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2)]);
        Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'failed', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2), 'error' => 'KoboUnreachable: KoboToolbox returned HTTP 503']);
    }, 'failed', 'KoboUnreachable'],
    'auth failed' => [function (): void {
        Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'succeeded', 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2)]);
        Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'failed', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2), 'error' => 'KoboAuthFailed: KoboToolbox refused the token (HTTP 401)']);
    }, 'auth_failed', 'KoboAuthFailed'],
    'a skipped run changes nothing' => [function (): void {
        Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);
        Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'skipped_not_configured', 'started_at' => now()->subMinute(), 'finished_at' => now()]);
    }, 'healthy', null],
    'a backfill changes nothing' => [function (): void {
        Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);
        Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'trigger_type' => 'backfill', 'outcome' => 'failed', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'error' => 'KoboAuthFailed: x']);
    }, 'healthy', null],
    'the other source\'s failure changes nothing' => [function (): void {
        Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);
        Rows::pullRun(['source_id' => Rows::sourceId('odk'), 'outcome' => 'failed', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'error' => 'OdkAuthFailed: x']);
    }, 'healthy', null],
]);

it('gives the failure\'s time, HTTP status and the last success', function (): void {
    Rows::pullRun(['source_id' => Rows::sourceId('odk'), 'outcome' => 'succeeded', 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2)]);
    Rows::pullRun(['source_id' => Rows::sourceId('odk'), 'outcome' => 'failed', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2), 'error' => 'OdkUnexpectedResponse: ODK Central returned HTTP 503']);

    $status = app(SourceStatusAggregator::class)->source('odk');

    expect($status['lastSuccessAt'])->toBe(now()->subHours(2)->startOfSecond()->toIso8601String())
        ->and($status['minutesSinceSuccess'])->toBe(120)
        ->and($status['sinceSuccess'])->toBe(['value' => 2, 'unit' => 'hours'])
        ->and($status['lastFailedAt'])->toBe(now()->subMinutes(2)->startOfSecond()->toIso8601String())
        ->and($status['lastErrorHttpStatus'])->toBe(503);
});

it('puts the worst state first for the status bar, every source named', function (): void {
    config(['edqa.sources.odk.enabled' => false]); // disabled
    Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'failed', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2), 'error' => 'KoboAuthFailed: x']);

    $all = app(SourceStatusAggregator::class)->all();

    expect(array_column($all, 'key'))->toBe(['kobo', 'odk'])
        ->and(array_column($all, 'state'))->toBe(['auth_failed', 'disabled'])
        ->and(array_column($all, 'name'))->toBe(['KoboToolbox', 'ODK Central']);
});

it('shares every source\'s state with the shell, worst first', function (): void {
    Rows::pullRun(['source_id' => Rows::sourceId('odk'), 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);
    Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(50), 'finished_at' => now()->subMinutes(45)]);

    // The admin layout's status bar (admin pages only, never the public site).
    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn ($page) => $page
        ->where('shell.sources.0.key', 'kobo')->where('shell.sources.0.state', 'stale')
        ->where('shell.sources.1.key', 'odk')->where('shell.sources.1.state', 'healthy')
        ->missing('shell.sync'));
});
