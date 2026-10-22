<?php

declare(strict_types=1);

use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\OdkUnreachable;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSubmissionSource;
use Tests\Support\FixtureWorld;
use Tests\Support\Rows;

// The ODK pull page (ARCHITECTURE.md §9.3, D-56): the connection, the history, the backfill panel
// and its report link.

beforeEach(function (): void {
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Amina Bello']);
    $this->source = new FakeSubmissionSource;
    app()->instance(SubmissionSource::class, $this->source);
    Storage::fake('exports');
});

function odkPageConfigure(): void
{
    config(['edqa.odk' => [...config('edqa.odk'), 'url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'secret-for-tests', 'project_id' => '7', 'form_id' => 'dqa']]);
}

it('is behind the sign-in', function (): void {
    $this->get('/admin/odk')->assertRedirect('/login');
});

it('says what is missing when ODK is not configured, never the values, and links the runbook', function (): void {
    config(['edqa.odk.url' => null, 'edqa.odk.password' => null]);
    config(['edqa.odk.email' => 'secret-mail@example.org']);

    $response = $this->actingAs($this->admin)->get('/admin/odk');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/odk')
        ->where('status.configured', false)
        ->where('status.reachable', null)
        ->where('status.sync.state', 'not_configured')
        ->where('status.problems', fn ($problems): bool => in_array('ODK_CENTRAL_URL', $problems->all(), true) && in_array('ODK_CENTRAL_PASSWORD', $problems->all(), true))
        ->where('docs.runbook', 'https://github.com/naynie41/kaduna-eDQA-dashboard/blob/main/docs/runbooks/odk-credentials-day.md')
        ->has('translations.admin.odk.connection'));
    expect($response->getContent())->not->toContain('secret-mail@example.org');
});

it('gives the connection when configured and answering, with the last pull and the open round', function (): void {
    odkPageConfigure();
    $round = Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
    Rows::assessment(['round_id' => $round, 'status' => 'accepted']);
    Rows::quarantined(['round_id' => $round]);
    Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4), 'fetched' => 12, 'parsed' => 10, 'accepted' => 9, 'quarantined' => 1, 'duplicates' => 2]);

    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page
        ->where('status.configured', true)
        ->where('status.reachable.ok', true)
        ->where('status.sync.state', 'ok')
        ->where('status.sync.sinceSuccess', ['value' => 4, 'unit' => 'minutes'])
        ->where('status.last_run.fetched', 12)
        ->where('status.last_run.parsed', 10)
        ->where('status.open_rounds.0.accepted', 1)
        ->where('status.open_rounds.0.quarantined', 1));
});

it('names the HTTP status of the last failure, and the time since the last success in hours or days', function (): void {
    odkPageConfigure();
    Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subHours(50), 'finished_at' => now()->subHours(50)]);
    Rows::pullRun(['outcome' => 'failed', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2), 'error' => 'OdkUnreachable: ODK Central kept answering HTTP 503 after 3 attempts.']);

    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page
        ->where('status.sync.state', 'failed')
        ->where('status.sync.lastErrorClass', 'OdkUnreachable')
        ->where('status.sync.lastErrorHttpStatus', 503)
        ->where('status.sync.sinceSuccess', ['value' => 2, 'unit' => 'days']));
});

it('says when ODK Central is configured but not reachable', function (): void {
    odkPageConfigure();
    $source = Mockery::mock(SubmissionSource::class);
    $source->shouldReceive('ping')->andThrow(OdkUnreachable::connection(3));
    app()->instance(SubmissionSource::class, $source);

    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page
        ->where('status.reachable.ok', false)
        ->where('status.reachable.status', 'unreachable'));
});

it('follows a pull already running', function (): void {
    odkPageConfigure();
    $run = OdkPullRun::query()->create(['trigger_type' => PullTrigger::Scheduled, 'started_at' => now()]);

    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page->where('status.running_run_id', $run->id));
});

it('lists the history newest first, 25 a page, with who triggered each and its duration', function (): void {
    foreach (range(1, 27) as $n) {
        Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subMinutes(100 - $n), 'finished_at' => now()->subMinutes(100 - $n)->addSeconds(92)]);
    }
    $manual = Rows::pullRun(['trigger_type' => 'manual', 'triggered_by' => $this->admin->id, 'outcome' => 'failed', 'started_at' => now(), 'finished_at' => now()->addSeconds(4), 'error' => 'RuntimeException: boom']);

    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page
        ->where('history.total', 28)
        ->where('history.last_page', 2)
        ->count('history.data', 25)
        ->where('history.data.0', fn ($row): bool => $row['id'] === $manual && $row['triggered_by'] === 'Amina Bello' && $row['outcome'] === 'failed'
            && $row['duration'] === ['minutes' => 0, 'seconds' => 4] && $row['error'] === 'RuntimeException: boom')
        ->where('history.data.1.triggered_by', null)
        ->where('history.data.1.duration', ['minutes' => 1, 'seconds' => 32]));

    $this->actingAs($this->admin)->get('/admin/odk?page=2')->assertInertia(fn (Assert $page) => $page->count('history.data', 3));
});

it('filters the history by trigger and by outcome, a running one included', function (array $query, int $expected): void {
    Rows::pullRun(['trigger_type' => 'scheduled', 'outcome' => 'succeeded', 'finished_at' => now()]);
    Rows::pullRun(['trigger_type' => 'manual', 'outcome' => 'failed', 'finished_at' => now()]);
    Rows::pullRun(['trigger_type' => 'webhook', 'outcome' => null]);
    Rows::pullRun(['trigger_type' => 'backfill', 'outcome' => 'succeeded', 'finished_at' => now()]);

    $this->actingAs($this->admin)->get('/admin/odk?'.http_build_query($query))->assertInertia(fn (Assert $page) => $page
        ->where('history.total', $expected)
        ->where('historyFilters', ['trigger' => $query['trigger'] ?? null, 'outcome' => $query['outcome'] ?? null]));
})->with([
    'all' => [[], 4],
    'scheduled' => [['trigger' => 'scheduled'], 1],
    'backfill' => [['trigger' => 'backfill'], 1],
    'succeeded' => [['outcome' => 'succeeded'], 2],
    'running' => [['outcome' => 'running'], 1],
    'manual and failed' => [['trigger' => 'manual', 'outcome' => 'failed'], 1],
    'manual and succeeded' => [['trigger' => 'manual', 'outcome' => 'succeeded'], 0],
]);

it('refuses a filter it does not know', function (string $query): void {
    $this->actingAs($this->admin)->get("/admin/odk?{$query}")->assertSessionHasErrors();
})->with(['trigger=cron', 'outcome=partial', 'page=0']);

it('shows the last backfill, its checkpoint page, and a signed link to the newest report', function (): void {
    odkPageConfigure();
    config(['edqa.odk.page_size' => 500]);
    DB::table('odk_form_syncs')->insert(['project_id' => 7, 'form_id' => 'dqa', 'backfill_skip' => 1500, 'created_at' => now(), 'updated_at' => now()]);
    Rows::pullRun(['trigger_type' => 'backfill', 'outcome' => 'succeeded', 'started_at' => now()->subDay(), 'finished_at' => now()->subDay()->addHour(), 'fetched' => 1500, 'accepted' => 1400]);
    Storage::disk('exports')->put('backfill-report-20261019-090000.md', '# old');
    Storage::disk('exports')->put('backfill-report-20261020-090000.md', '# Backfill report');
    Storage::disk('exports')->put('cascade/odk-cascade-20261020-090000.zip', 'zip');

    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page
        ->where('backfill.run.fetched', 1500)
        ->where('backfill.checkpoint', ['position' => 1500, 'page' => 3, 'page_size' => 500])
        ->where('backfill.report.file', 'backfill-report-20261020-090000.md')
        ->where('backfill.report.url', fn (string $url): bool => str_contains($url, '/api/odk/backfill-report/backfill-report-20261020-090000.md') && str_contains($url, 'signature=')));
});

it('says no backfill has run and no report exists', function (): void {
    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page
        ->where('backfill.run', null)
        ->where('backfill.report', null)
        ->where('backfill.checkpoint.position', 0));
});

it('downloads the backfill report through its signed link only, audited', function (): void {
    Storage::disk('exports')->put('backfill-report-20261020-090000.md', '# Backfill report');
    $signed = URL::temporarySignedRoute('api.odk.backfill-report', now()->addMinutes(30), ['file' => 'backfill-report-20261020-090000.md']);

    $this->actingAs($this->admin)->get('/api/odk/backfill-report/backfill-report-20261020-090000.md')->assertForbidden();
    $response = $this->actingAs($this->admin)->get($signed)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe('# Backfill report')
        ->and(Activity::query()->where('event', 'backfill_report_downloaded')->sole()->causer?->is($this->admin))->toBeTrue();

    $other = URL::temporarySignedRoute('api.odk.backfill-report', now()->addMinutes(30), ['file' => 'odk-cascade-20261020-090000.zip']);
    $this->actingAs($this->admin)->get($other)->assertNotFound();
    // Signed in as well as signed: the route is in the auth group (RouteGuardTest).
});

it('counts the rows a pull parsed, for the progress panel', function (): void {
    FixtureWorld::build();
    CurrentRuleVersion::forget();
    odkPageConfigure();
    $entry = json_decode((string) file_get_contents(base_path('tests/Fixtures/odk/valid/clean-visit.json')), true);
    $this->source->add($entry);

    $run = app(PullSubmissions::class)->execute(PullTrigger::Scheduled)->refresh();
    $again = app(PullSubmissions::class)->execute(PullTrigger::Scheduled)->refresh();

    expect([$run->fetched, $run->parsed, $run->duplicates])->toBe([1, 1, 0])
        ->and([$again->fetched, $again->parsed, $again->duplicates])->toBe([1, 0, 1]);
    $this->actingAs($this->admin)->getJson("/api/odk/pull/{$run->id}")->assertJsonPath('data.parsed', 1);
});
