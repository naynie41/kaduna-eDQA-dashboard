<?php

declare(strict_types=1);

use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\OdkUnreachable;
use App\Domain\Ingestion\Models\PullRun;
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

// The Data sources page (ARCHITECTURE.md §9.3), which replaced the ODK pull page: a panel per
// source, the combined history, the deletions at source, the backfill panel and its report link.

beforeEach(function (): void {
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Amina Bello']);
    $this->source = new FakeSubmissionSource;
    useSource('odk', $this->source);
    Storage::fake('exports');
});

function odkPageConfigure(): void
{
    config(['edqa.sources.odk' => [...config('edqa.sources.odk'), 'url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'secret-for-tests', 'project_id' => '7', 'form_id' => 'dqa']]);
}

it('is behind the sign-in', function (): void {
    $this->get('/admin/sources')->assertRedirect('/login');
});

it('says what is missing when ODK is not configured, never the values, and links the runbook', function (): void {
    config(['edqa.sources.odk.url' => null, 'edqa.sources.odk.password' => null]);
    config(['edqa.sources.odk.email' => 'secret-mail@example.org']);

    $response = $this->actingAs($this->admin)->get('/admin/sources');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/sources')
        ->where('sources.0.configured', false)
        ->where('sources.0.reachable', null)
        ->where('sources.0.status.state', 'not_configured')
        ->where('sources.0.problems', fn ($problems): bool => in_array('ODK_CENTRAL_URL', $problems->all(), true) && in_array('ODK_CENTRAL_PASSWORD', $problems->all(), true))
        ->where('docs.runbook', 'https://github.com/naynie41/kaduna-eDQA-dashboard/blob/main/docs/runbooks/source-credentials-day.md')
        ->has('translations.admin.sources.panel'));
    expect($response->getContent())->not->toContain('secret-mail@example.org');
});

it('gives the connection when configured and answering, with the last pull and the open round', function (): void {
    odkPageConfigure();
    $round = Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
    Rows::assessment(['round_id' => $round, 'status' => 'accepted']);
    Rows::quarantined(['round_id' => $round]);
    Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4), 'fetched' => 12, 'parsed' => 10, 'accepted' => 9, 'quarantined' => 1, 'duplicates' => 2]);

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('sources.0.configured', true)
        ->where('sources.0.reachable.ok', true)
        ->where('sources.0.status.state', 'healthy')
        ->where('sources.0.status.sinceSuccess', ['value' => 4, 'unit' => 'minutes'])
        ->where('sources.0.last_run.fetched', 12)
        ->where('sources.0.last_run.parsed', 10)
        ->where('sources.0.open_rounds.0.accepted', 1)
        ->where('sources.0.open_rounds.0.quarantined', 1));
});

it('names the HTTP status of the last failure, and the time since the last success in hours or days', function (): void {
    odkPageConfigure();
    Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subHours(50), 'finished_at' => now()->subHours(50)]);
    Rows::pullRun(['outcome' => 'failed', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2), 'error' => 'OdkUnreachable: ODK Central kept answering HTTP 503 after 3 attempts.']);

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('sources.0.status.state', 'failed')
        ->where('sources.0.status.lastErrorClass', 'OdkUnreachable')
        ->where('sources.0.status.lastErrorHttpStatus', 503)
        ->where('sources.0.status.sinceSuccess', ['value' => 2, 'unit' => 'days']));
});

it('says when ODK Central is configured but not reachable', function (): void {
    odkPageConfigure();
    $source = Mockery::mock(SubmissionSource::class);
    $source->shouldReceive('ping')->andThrow(OdkUnreachable::connection(3));
    useSource('odk', $source);

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('sources.0.reachable.ok', false)
        ->where('sources.0.reachable.status', 'unreachable'));
});

it('follows a pull already running', function (): void {
    odkPageConfigure();
    $run = PullRun::query()->create(['source_id' => Rows::sourceId(), 'trigger_type' => PullTrigger::Scheduled, 'started_at' => now()]);

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page->where('sources.0.running_run_id', $run->id));
});

it('lists the history newest first, 25 a page, with who triggered each and its duration', function (): void {
    foreach (range(1, 27) as $n) {
        Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subMinutes(100 - $n), 'finished_at' => now()->subMinutes(100 - $n)->addSeconds(92)]);
    }
    $manual = Rows::pullRun(['trigger_type' => 'manual', 'triggered_by' => $this->admin->id, 'outcome' => 'failed', 'started_at' => now(), 'finished_at' => now()->addSeconds(4), 'error' => 'RuntimeException: boom']);

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('history.total', 28)
        ->where('history.last_page', 2)
        ->count('history.data', 25)
        ->where('history.data.0', fn ($row): bool => $row['id'] === $manual && $row['triggered_by'] === 'Amina Bello' && $row['outcome'] === 'failed'
            && $row['duration'] === ['minutes' => 0, 'seconds' => 4] && $row['error'] === 'RuntimeException: boom')
        ->where('history.data.1.triggered_by', null)
        ->where('history.data.1.duration', ['minutes' => 1, 'seconds' => 32]));

    $this->actingAs($this->admin)->get('/admin/sources?page=2')->assertInertia(fn (Assert $page) => $page->count('history.data', 3));
});

it('filters the history by trigger and by outcome, a running one included', function (array $query, int $expected): void {
    Rows::pullRun(['trigger_type' => 'scheduled', 'outcome' => 'succeeded', 'finished_at' => now()]);
    Rows::pullRun(['trigger_type' => 'manual', 'outcome' => 'failed', 'finished_at' => now()]);
    Rows::pullRun(['trigger_type' => 'webhook', 'outcome' => null]);
    Rows::pullRun(['trigger_type' => 'backfill', 'outcome' => 'succeeded', 'finished_at' => now()]);

    $this->actingAs($this->admin)->get('/admin/sources?'.http_build_query($query))->assertInertia(fn (Assert $page) => $page
        ->where('history.total', $expected)
        ->where('historyFilters', ['source' => null, 'trigger' => $query['trigger'] ?? null, 'outcome' => $query['outcome'] ?? null]));
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
    $this->actingAs($this->admin)->get("/admin/sources?{$query}")->assertSessionHasErrors();
})->with(['trigger=cron', 'outcome=partial', 'page=0', 'source=dhis2', 'source[]=odk']);

it('shows the last backfill, its checkpoint page, and a signed link to the newest report', function (): void {
    odkPageConfigure();
    config(['edqa.sources.odk.page_size' => 500]);
    DB::table('source_syncs')->insert(['source_id' => Rows::sourceId(), 'form_ref' => '7/dqa', 'backfill_checkpoint' => 1500, 'created_at' => now(), 'updated_at' => now()]);
    Rows::pullRun(['trigger_type' => 'backfill', 'outcome' => 'succeeded', 'started_at' => now()->subDay(), 'finished_at' => now()->subDay()->addHour(), 'fetched' => 1500, 'accepted' => 1400]);
    Storage::disk('exports')->put('backfill-report-20261019-090000.md', '# old');
    Storage::disk('exports')->put('backfill-report-20261020-090000.md', '# Backfill report');
    Storage::disk('exports')->put('cascade/odk-cascade-20261020-090000.zip', 'zip');

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('backfill.run.fetched', 1500)
        ->where('backfill.checkpoint', ['position' => 1500, 'page' => 3, 'page_size' => 500])
        ->where('backfill.report.file', 'backfill-report-20261020-090000.md')
        ->where('backfill.report.url', fn (string $url): bool => str_contains($url, '/api/backfill-reports/backfill-report-20261020-090000.md') && str_contains($url, 'signature=')));
});

it('says no backfill has run and no report exists', function (): void {
    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('backfill.run', null)
        ->where('backfill.report', null)
        ->where('backfill.checkpoint.position', 0));
});

it('downloads the backfill report through its signed link only, audited', function (): void {
    Storage::disk('exports')->put('backfill-report-20261020-090000.md', '# Backfill report');
    $signed = URL::temporarySignedRoute('api.backfill-reports.show', now()->addMinutes(30), ['file' => 'backfill-report-20261020-090000.md']);

    $this->actingAs($this->admin)->get('/api/backfill-reports/backfill-report-20261020-090000.md')->assertForbidden();
    $response = $this->actingAs($this->admin)->get($signed)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe('# Backfill report')
        ->and(Activity::query()->where('event', 'backfill_report_downloaded')->sole()->causer?->is($this->admin))->toBeTrue();

    $other = URL::temporarySignedRoute('api.backfill-reports.show', now()->addMinutes(30), ['file' => 'odk-cascade-20261020-090000.zip']);
    $this->actingAs($this->admin)->get($other)->assertNotFound();
    // Signed in as well as signed: the route is in the auth group (RouteGuardTest).
});

it('counts the rows a pull parsed, for the progress panel', function (): void {
    FixtureWorld::build();
    CurrentRuleVersion::forget();
    odkPageConfigure();
    $entry = json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/odk/valid/clean-visit.json')), true);
    $this->source->add($entry);

    $run = app(PullSubmissions::class)->execute('odk', PullTrigger::Scheduled)->refresh();
    $again = app(PullSubmissions::class)->execute('odk', PullTrigger::Scheduled)->refresh();

    expect([$run->fetched, $run->parsed, $run->duplicates])->toBe([1, 1, 0])
        ->and([$again->fetched, $again->parsed, $again->duplicates])->toBe([1, 0, 1]);
    $this->actingAs($this->admin)->getJson("/api/pulls/{$run->id}")->assertJsonPath('data.parsed', 1);
});

it('gives every configured source its own panel, in config order, each with its own counts', function (): void {
    odkPageConfigure();
    config(['edqa.sources.kobo' => [...config('edqa.sources.kobo'), 'url' => 'https://kf.example.org', 'token' => 'kobo-token-for-tests', 'asset_uid' => 'aKdDqa2026']]);
    useSource('kobo', new FakeSubmissionSource);
    $round = Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
    Rows::assessment(['round_id' => $round, 'submission_id' => Rows::submission(['source_id' => Rows::sourceId('kobo')])]);
    Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2), 'fetched' => 7, 'cross_source_duplicates' => 2]);

    $response = $this->actingAs($this->admin)->get('/admin/sources');

    $response->assertInertia(fn (Assert $page) => $page
        ->where('sources.0.key', 'odk')
        ->where('sources.1.key', 'kobo')
        ->where('sources.1.name', 'KoboToolbox')
        ->where('sources.1.configured', true)
        ->where('sources.1.status.state', 'healthy')
        ->where('sources.1.last_run.cross_source_duplicates', 2)
        ->where('sources.1.open_rounds.0.accepted', 1)
        ->where('sources.0.open_rounds.0.accepted', 0));
    expect($response->getContent())->not->toContain('kobo-token-for-tests');
});

it('names each run\'s source in the history, with its duplicates, and filters by source', function (): void {
    $odk = Rows::pullRun(['outcome' => 'succeeded', 'finished_at' => now(), 'duplicates' => 3]);
    $kobo = Rows::pullRun(['source_id' => Rows::sourceId('kobo'), 'trigger_type' => 'reconcile', 'outcome' => 'succeeded', 'started_at' => now()->addSecond(), 'finished_at' => now()->addSeconds(2), 'cross_source_duplicates' => 4, 'deletions_detected' => 1]);

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('history.total', 2)
        ->where('history.data.0', fn ($row): bool => $row['id'] === $kobo && $row['source'] === ['key' => 'kobo', 'name' => 'KoboToolbox'] && $row['trigger'] === 'reconcile'
            && $row['cross_source_duplicates'] === 4 && $row['deletions_detected'] === 1)
        ->where('history.data.1.source', ['key' => 'odk', 'name' => 'ODK Central'])
        ->where('history.data.1.duplicates', 3));

    $this->actingAs($this->admin)->get('/admin/sources?source=odk')->assertInertia(fn (Assert $page) => $page
        ->where('history.total', 1)
        ->where('history.data.0.id', $odk)
        ->where('historyFilters', ['source' => 'odk', 'trigger' => null, 'outcome' => null]));
});

it('lists the deletions at source, newest first, with what each touches and a link to its source', function (): void {
    odkPageConfigure();
    $round = Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
    $facility = Rows::facility(['code' => 'KD-DEL1', 'name' => 'Deleted PHC']);
    $assessed = Rows::submission(['external_id' => 'uuid:6f1c2a9e-3b4d-4e5f-8a7b-9c0d1e2f3a4b', 'deleted_at_source' => now()->subDay()]);
    $assessment = Rows::assessment(['round_id' => $round, 'facility_id' => $facility, 'submission_id' => $assessed]);
    $held = Rows::submission(['source_id' => Rows::sourceId('kobo'), 'deleted_at_source' => now()]);
    $record = Rows::quarantined(['round_id' => $round, 'submission_id' => $held]);
    Rows::submission(); // still at its source

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('deletions.total', 2)
        ->count('deletions.rows', 2)
        ->where('deletions.rows.0', fn ($row): bool => $row['submission_id'] === $held && $row['source'] === ['key' => 'kobo', 'name' => 'KoboToolbox']
            && $row['assessment'] === null && $row['quarantined_record_id'] === $record && $row['round']['id'] === $round && $row['source_url'] === null)
        ->where('deletions.rows.1', fn ($row): bool => $row['submission_id'] === $assessed
            && $row['facility'] === ['id' => $facility, 'code' => 'KD-DEL1', 'name' => 'Deleted PHC']
            && $row['assessment'] === ['id' => $assessment, 'status' => 'accepted']
            && $row['source_url'] === 'https://central.example.org/#/projects/7/forms/dqa/submissions/uuid%3A6f1c2a9e-3b4d-4e5f-8a7b-9c0d1e2f3a4b'));
});

it('sends the old ODK pull address, and /admin, to the Data sources page', function (): void {
    $this->actingAs($this->admin)->get('/admin/odk')->assertRedirect('/admin/sources')->assertStatus(301);
    $this->actingAs($this->admin)->get('/admin')->assertRedirect('/admin/sources');
});
