<?php

declare(strict_types=1);

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\OdkUnreachable;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Domain\Ingestion\Queries\OdkStatusQuery;
use App\Domain\Reporting\Aggregators\ShellStatusAggregator;
use App\Http\Controllers\OdkWebhookController;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSubmissionSource;
use Tests\Support\Rows;

// The webhook, "Pull now", pull progress and the ODK status (ARCHITECTURE.md §5.4, §8,
// SECURITY.md §6, D-54).

const WEBHOOK_SECRET = 'whsec-test-0123456789abcdef';

beforeEach(function (): void {
    config(['edqa.odk' => [...config('edqa.odk'),
        'url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'secret-for-tests', 'project_id' => '7', 'form_id' => 'dqa',
    ]]);
    config(['edqa.odk.webhook.secret' => WEBHOOK_SECRET]);
    $this->source = new FakeSubmissionSource;
    app()->instance(SubmissionSource::class, $this->source);
    $this->admin = User::factory()->withTwoFactor()->create();
});

/**
 * POST /webhooks/odk with a body, signed (or not) as the sender would.
 *
 * @param  array<string, string>|null  $headers  null: signed correctly now
 */
function webhook(object $test, string $body = '{"event":"submission.created"}', ?array $headers = null): TestResponse
{
    $timestamp = (string) now()->getTimestamp();
    $headers ??= [
        OdkWebhookController::TIMESTAMP => $timestamp,
        OdkWebhookController::SIGNATURE => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, WEBHOOK_SECRET),
    ];
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call('POST', '/webhooks/odk', [], [], [], [...$server, 'CONTENT_TYPE' => 'application/json'], $body);
}

function webhookSigned(string $body, int $timestamp, string $secret = WEBHOOK_SECRET): array
{
    return [
        OdkWebhookController::TIMESTAMP => (string) $timestamp,
        OdkWebhookController::SIGNATURE => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ];
}

/** Row counts of every table but the job queue, cache and activity log. @return array<string, int> */
function odkApiTableCounts(): array
{
    $counts = [];
    foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $table) {
        $counts[$table->tablename] = DB::table($table->tablename)->count();
    }

    return $counts;
}

// ---- POST /webhooks/odk

it('accepts a valid signature: it queues a webhook pull and answers 202', function (): void {
    Queue::fake();

    webhook($this)->assertStatus(202)->assertJson(['message' => __('odk.webhook.queued')]);

    Queue::assertPushed(PullSubmissionsJob::class, fn (PullSubmissionsJob $job): bool => $job->trigger === PullTrigger::Webhook && $job->userId === null && $job->runId === null);
});

it('refuses a missing, invalid or stale signature with 401, queueing nothing', function (array $headers, string $code): void {
    Queue::fake();
    $body = '{"event":"submission.created"}';
    $headers = array_map(fn (string $v): string => strtr($v, ['{now}' => (string) now()->getTimestamp()]), $headers);
    if (($headers['sign'] ?? null) !== null) {
        [$timestamp, $secret, $signedBody] = explode('|', $headers['sign']);
        $headers = webhookSigned($signedBody === '' ? $body : $signedBody, (int) $timestamp, $secret);
    }

    webhook($this, $body, $headers)->assertStatus(401)->assertJson(['code' => $code]);

    Queue::assertNothingPushed();
})->with([
    'no headers' => [[], 'signature_missing'],
    'no signature' => [[OdkWebhookController::TIMESTAMP => '{now}'], 'signature_missing'],
    'no timestamp' => [[OdkWebhookController::SIGNATURE => 'sha256=abc'], 'signature_missing'],
    'timestamp not a number' => [[OdkWebhookController::TIMESTAMP => 'yesterday', OdkWebhookController::SIGNATURE => 'sha256=abc'], 'signature_missing'],
    'wrong secret' => [['sign' => '{now}|another-secret|'], 'signature_invalid'],
    'another body' => [['sign' => '{now}|'.WEBHOOK_SECRET.'|{"event":"other"}'], 'signature_invalid'],
    'garbage signature' => [[OdkWebhookController::TIMESTAMP => '{now}', OdkWebhookController::SIGNATURE => 'sha256=00'], 'signature_invalid'],
]);

it('refuses a timestamp more than five minutes off, even correctly signed', function (int $offset): void {
    Queue::fake();
    $body = '{}';

    webhook($this, $body, webhookSigned($body, now()->getTimestamp() + $offset))->assertStatus(401)->assertJson(['code' => 'timestamp_stale']);

    Queue::assertNothingPushed();
})->with(['six minutes old' => -360, 'six minutes ahead' => 360]);

it('accepts a timestamp just inside the five minutes', function (): void {
    Queue::fake();
    $body = '{}';

    webhook($this, $body, webhookSigned($body, now()->getTimestamp() - 290))->assertStatus(202);
});

it('is switched off (404) when no secret is configured', function (?string $secret): void {
    Queue::fake();
    config(['edqa.odk.webhook.secret' => $secret]);

    webhook($this)->assertNotFound();

    Queue::assertNothingPushed();
})->with([null, '']);

it('never treats the body as data: a signed body full of submissions writes nothing', function (): void {
    Queue::fake(); // the pull it queues is the only effect
    $before = odkApiTableCounts();
    $body = (string) json_encode(['value' => [['__id' => 'uuid:evil', 'meta' => ['instanceID' => 'uuid:evil'], 'facility' => ['facility_code' => 'X']]]]);

    webhook($this, $body)->assertStatus(202);

    expect(Arr::except(odkApiTableCounts(), ['activity_log', 'cache', 'cache_locks', 'jobs']))->toBe(Arr::except($before, ['activity_log', 'cache', 'cache_locks', 'jobs']))
        ->and(DB::table('submissions')->where('instance_id', 'uuid:evil')->exists())->toBeFalse();
    Queue::assertPushed(PullSubmissionsJob::class, 1);
});

it('queues at most one pull a minute however many webhooks arrive', function (): void {
    Queue::fake();

    webhook($this)->assertStatus(202);
    webhook($this)->assertStatus(202);
    webhook($this)->assertStatus(202);

    Queue::assertPushed(PullSubmissionsJob::class, 1);
});

it('is rate limited', function (): void {
    Queue::fake();
    foreach (range(1, 60) as $i) {
        webhook($this, '{}', [])->assertStatus(401);
    }

    webhook($this, '{}', [])->assertStatus(429);
});

// ---- POST /api/odk/pull and GET /api/odk/pull/{run}

it('starts a manual pull, audited, and answers 202 with its run id', function (): void {
    Queue::fake();

    $response = $this->actingAs($this->admin)->postJson('/api/odk/pull')->assertStatus(202)->assertJson(['already_running' => false]);

    $run = OdkPullRun::query()->sole();
    expect($response->json('run_id'))->toBe($run->id)
        ->and($run->trigger_type)->toBe(PullTrigger::Manual)
        ->and($run->triggered_by)->toBe($this->admin->id)
        ->and($run->outcome)->toBeNull();
    Queue::assertPushed(PullSubmissionsJob::class, fn (PullSubmissionsJob $job): bool => $job->trigger === PullTrigger::Manual && $job->userId === $this->admin->id && $job->runId === $run->id);
    $entry = Activity::query()->forSubject($run)->where('event', 'pull_requested')->sole();
    expect($entry->causer?->is($this->admin))->toBeTrue()
        ->and($entry->properties['joined_running'])->toBeFalse();
});

it('returns the running pull instead of queueing a second, and audits that too', function (): void {
    Queue::fake();
    $running = OdkPullRun::query()->create(['trigger_type' => PullTrigger::Scheduled, 'started_at' => now()->subMinute()]);

    $this->actingAs($this->admin)->postJson('/api/odk/pull')->assertStatus(202)->assertJson(['run_id' => $running->id, 'already_running' => true]);

    expect(OdkPullRun::query()->count())->toBe(1);
    Queue::assertNothingPushed();
    expect(Activity::query()->forSubject($running)->where('event', 'pull_requested')->sole()->properties['joined_running'])->toBeTrue();
});

it('does not count a backfill, or a run left open long ago, as a running pull', function (): void {
    Queue::fake();
    OdkPullRun::query()->create(['trigger_type' => PullTrigger::Backfill, 'started_at' => now()->subMinute()]);
    OdkPullRun::query()->create(['trigger_type' => PullTrigger::Scheduled, 'started_at' => now()->subHours(3)]);

    $this->actingAs($this->admin)->postJson('/api/odk/pull')->assertStatus(202)->assertJson(['already_running' => false]);

    Queue::assertPushed(PullSubmissionsJob::class, 1);
});

it('carries the run it was given through the pull, without a second audit entry', function (): void {
    $this->source->add(json_decode((string) file_get_contents(base_path('tests/Fixtures/odk/valid/clean-visit.json')), true));

    // The sync test queue runs the pull at once.
    $runId = $this->actingAs($this->admin)->postJson('/api/odk/pull')->assertStatus(202)->json('run_id');

    $run = OdkPullRun::query()->sole();
    expect($run->id)->toBe($runId)
        ->and($run->outcome)->toBe(PullOutcome::Succeeded)
        ->and($run->fetched)->toBe(1)
        ->and(Activity::query()->where('event', 'pull_requested')->count())->toBe(1);
});

it('closes the run it was given when the pull never got the lock', function (): void {
    $run = OdkPullRun::query()->create(['trigger_type' => PullTrigger::Manual, 'triggered_by' => $this->admin->id, 'started_at' => now()]);

    (new PullSubmissionsJob(PullTrigger::Manual, $this->admin->id, $run->id))->failed(new RuntimeException('lock busy'));

    expect($run->refresh()->outcome)->toBe(PullOutcome::Failed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->toBe('RuntimeException: lock busy');
});

it('limits "Pull now" to six a minute', function (): void {
    Queue::fake();
    foreach (range(1, 6) as $i) {
        $this->actingAs($this->admin)->postJson('/api/odk/pull')->assertStatus(202);
    }

    $this->actingAs($this->admin)->postJson('/api/odk/pull')->assertStatus(429);
});

it('shows a run\'s progress, and 404 for one that does not exist', function (): void {
    $run = OdkPullRun::query()->create(['trigger_type' => PullTrigger::Manual, 'started_at' => now(), 'fetched' => 500, 'duplicates' => 3, 'accepted' => 480, 'flagged' => 7, 'quarantined' => 10]);

    $this->actingAs($this->admin)->getJson("/api/odk/pull/{$run->id}")->assertOk()->assertExactJson(['data' => [
        'run_id' => $run->id, 'trigger' => 'manual', 'status' => 'running', 'started_at' => $run->started_at->toIso8601String(), 'finished_at' => null,
        'fetched' => 500, 'duplicates' => 3, 'accepted' => 480, 'flagged' => 7, 'quarantined' => 10, 'error' => null,
    ]]);
    $run->update(['outcome' => PullOutcome::Failed, 'finished_at' => now(), 'error' => 'OdkUnreachable: ODK Central could not be reached']);
    $this->actingAs($this->admin)->getJson("/api/odk/pull/{$run->id}")->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'OdkUnreachable: ODK Central could not be reached');

    $this->actingAs($this->admin)->getJson('/api/odk/pull/999999')->assertNotFound();
});

it('keeps every ODK endpoint behind the sign-in', function (string $method, string $uri): void {
    $this->json($method, $uri)->assertUnauthorized();
    expect(OdkPullRun::query()->count())->toBe(0);
})->with([['POST', '/api/odk/pull'], ['GET', '/api/odk/pull/1'], ['GET', '/api/odk/status']]);

// ---- GET /api/odk/status and the status bar's states

it('reports configured, reachable, the cursor, the running pull and the open rounds\' counts', function (): void {
    $q2 = Rows::round(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);
    Rows::round(['year' => 2026, 'quarter' => 1, 'window_start' => '2026-01-01', 'window_end' => '2026-03-31', 'status' => 'closed']);
    Rows::assessment(['round_id' => $q2, 'status' => 'accepted']);
    Rows::assessment(['round_id' => $q2, 'status' => 'flagged']);
    Rows::quarantined(['round_id' => $q2]);
    DB::table('odk_form_syncs')->insert(['project_id' => 7, 'form_id' => 'dqa', 'last_pulled_at' => '2026-10-20 09:00:00+01', 'backfill_skip' => 1500, 'submission_count' => 1502, 'created_at' => now(), 'updated_at' => now()]);
    Rows::pullRun(['trigger_type' => 'scheduled', 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);
    $running = OdkPullRun::query()->create(['trigger_type' => PullTrigger::Scheduled, 'started_at' => now()]);

    $data = $this->actingAs($this->admin)->getJson('/api/odk/status')->assertOk()->json('data');

    expect($data['configured'])->toBeTrue()
        ->and($data['problems'])->toBe([])
        ->and($data['reachable'])->toMatchArray(['ok' => true, 'status' => 'ok'])
        ->and($data['cursor'])->toMatchArray(['backfill_skip' => 1500, 'submission_count' => 1502])
        ->and($data['cursor']['last_pulled_at'])->toStartWith('2026-10-20T')
        ->and($data['running_run_id'])->toBe($running->id)
        ->and($data['sync'])->toMatchArray(['state' => 'ok', 'minutesSinceSuccess' => 4, 'lastError' => null])
        ->and($data['open_rounds'])->toBe([['id' => $q2, 'year' => 2026, 'quarter' => 2, 'accepted' => 1, 'flagged' => 1, 'quarantined' => 1]]);
});

it('says when ODK Central does not answer, and asks it at most once a minute', function (): void {
    $source = Mockery::mock(SubmissionSource::class);
    $source->shouldReceive('ping')->once()->andThrow(OdkUnreachable::connection(3));
    app()->instance(SubmissionSource::class, $source);

    $first = $this->actingAs($this->admin)->getJson('/api/odk/status')->json('data.reachable');
    $second = $this->actingAs($this->admin)->getJson('/api/odk/status')->json('data.reachable');

    expect($first)->toMatchArray(['ok' => false, 'status' => 'unreachable'])
        ->and($second)->toBe($first)
        ->and(Cache::has(OdkStatusQuery::CACHE_KEY))->toBeTrue();
});

it('names the missing settings, never their values, when not configured, and asks ODK nothing', function (): void {
    config(['edqa.odk.url' => null, 'edqa.odk.password' => null]);
    $source = Mockery::mock(SubmissionSource::class);
    $source->shouldNotReceive('ping');
    app()->instance(SubmissionSource::class, $source);

    $response = $this->actingAs($this->admin)->getJson('/api/odk/status')->assertOk();

    expect($response->json('data.configured'))->toBeFalse()
        ->and($response->json('data.problems'))->toContain('ODK_CENTRAL_URL', 'ODK_CENTRAL_PASSWORD')
        ->and($response->json('data.reachable'))->toBeNull()
        ->and($response->json('data.sync.state'))->toBe('not_configured')
        ->and($response->getContent())->not->toContain('secret-for-tests');
});

it('gives the status bar each state', function (Closure $setup, string $state, ?string $errorClass): void {
    $setup();

    $sync = app(ShellStatusAggregator::class)->sync();

    expect($sync['state'])->toBe($state)
        ->and($sync['lastErrorClass'])->toBe($errorClass);
})->with([
    'not configured' => [fn () => config(['edqa.odk.url' => null]), 'not_configured', null],
    'not configured, whatever ran before' => [function (): void {
        config(['edqa.odk.url' => null]);
        Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2)]);
    }, 'not_configured', null],
    'configured, no pull yet' => [fn () => null, 'not_connected', null],
    'healthy' => [fn () => Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]), 'ok', null],
    'stale, past 30 minutes' => [fn () => Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subMinutes(46), 'finished_at' => now()->subMinutes(45)]), 'stale', null],
    'failed' => [function (): void {
        Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2)]);
        Rows::pullRun(['outcome' => 'failed', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2), 'error' => 'OdkUnreachable: ODK Central could not be reached after 3 attempts']);
    }, 'failed', 'OdkUnreachable'],
    'a skipped run changes nothing' => [function (): void {
        Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);
        Rows::pullRun(['outcome' => 'skipped_not_configured', 'started_at' => now()->subMinute(), 'finished_at' => now()]);
    }, 'ok', null],
]);

it('gives the time of the last success with a failure', function (): void {
    Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2)]);
    Rows::pullRun(['outcome' => 'failed', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2), 'error' => 'QueryException: SQLSTATE 23514']);

    $sync = app(ShellStatusAggregator::class)->sync();

    expect($sync['lastSuccessAt'])->toBe(now()->subHours(2)->startOfSecond()->toIso8601String())
        ->and($sync['minutesSinceSuccess'])->toBe(120)
        ->and($sync['lastErrorClass'])->toBe('QueryException');
});
