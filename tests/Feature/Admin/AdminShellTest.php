<?php

declare(strict_types=1);

use App\Domain\Ingestion\Events\DeletionReviewed;
use App\Domain\Ingestion\Events\SourceDeletionDetected;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Events\SubmissionQuarantined;
use App\Domain\Validation\Queries\QuarantineQuery;
use App\Models\User;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rows;

// The administration shell (ARCHITECTURE.md §9.3, D-55): the five pages, the Data issues badge's
// cached count, and the stale-ingestion banner (SECURITY.md §12).

beforeEach(function (): void {
    $this->admin = User::factory()->create();
});

const ADMIN_PAGES = [
    'sources' => 'admin/sources',
    'data-issues' => 'admin/data-issues',
    'rounds' => 'admin/rounds',
    'facilities' => 'admin/facilities',
    'scoring-rules' => 'admin/scoring-rules',
    'audit' => 'admin/audit',
];

function configureOdk(): void
{
    config(['edqa.sources.odk' => [...config('edqa.sources.odk'), 'url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'x', 'project_id' => '7', 'form_id' => 'dqa']]);
    Rows::sourceId('odk');
}

function shellConfigureKobo(): void
{
    config(['edqa.sources.kobo' => [...config('edqa.sources.kobo'), 'url' => 'https://kf.example.org', 'token' => 'kobo-token-for-tests', 'asset_uid' => 'aKdDqa2026']]);
    Rows::sourceId('kobo');
}

it('redirects /admin to the Data sources page', function (): void {
    $this->actingAs($this->admin)->get('/admin')->assertRedirect('/admin/sources');
});

it('tells an administrator sent back to sign in that the session expired, and nothing otherwise', function (): void {
    $this->get('/login?expired=1')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('auth/login')
        ->where('status', 'Your session has expired. Sign in again to carry on.'));

    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('status', null));
    $this->get('/login?expired=<script>')->assertInertia(fn (Assert $page) => $page->where('status', null));
});

it('serves each admin page with the admin props and strings, and no dashboard filters', function (string $path, string $component): void {
    $this->actingAs($this->admin)->get("/admin/{$path}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('admin.openIssueCount', 0)
            ->where('admin.staleIngestion.stale', false)
            ->has('translations.admin.tabs')
            ->missing('filters')
            ->has('shell.sources'));
})->with(array_map(fn (string $component, string $path): array => [$path, $component], ADMIN_PAGES, array_keys(ADMIN_PAGES)));

it('keeps every admin page behind the sign-in', function (string $path): void {
    $this->get("/admin/{$path}")->assertRedirect('/login');
})->with(array_keys(ADMIN_PAGES));

it('does not compute the admin props or the source status for a public page, even in the admin preview', function (): void {
    $this->seed(LgaSeeder::class);

    // The admin strings come along for the preview bar; the counts and the status do not.
    $this->actingAs($this->admin)->get('/')
        ->assertInertia(fn (Assert $page) => $page->missing('admin')->missing('shell')->has('translations.admin.preview'));
});

it('shows the open quarantine count, cached, and forgets it when a record is quarantined or resolved', function (): void {
    Rows::quarantined();
    Rows::quarantined();
    Rows::quarantined(['status' => 'resolved', 'resolution' => 'rejected', 'resolved_at' => now()]);

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page->where('admin.openIssueCount', 2)->where('shell.quarantined', 2));
    expect(Cache::get(QuarantineQuery::OPEN_ISSUES_KEY))->toBe(2);

    // A row written another way is not seen until the cache is forgotten (or lapses after 60 s)…
    $third = Rows::quarantined();
    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page->where('admin.openIssueCount', 2));

    // …which a quarantine does,
    SubmissionQuarantined::dispatch($third, 1);
    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page->where('admin.openIssueCount', 3));

    // and a resolution.
    DB::table('quarantined_records')->where('id', $third)->update(['status' => 'resolved']);
    QuarantineResolved::dispatch($third, null, QuarantineResolution::Rejected);
    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page->where('admin.openIssueCount', 2));
});

it('counts unreviewed deletions at source as open issues, and forgets the count when one is found or reviewed', function (): void {
    Rows::quarantined();
    Rows::submission(['deleted_at_source' => now()->subDay()]);
    Rows::submission(['deleted_at_source' => now()->subDay(), 'deletion_reviewed_at' => now(), 'deletion_reviewed_by' => $this->admin->id]);
    Rows::submission(); // still at its source

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('admin.openIssueCount', 2) // 1 open record + 1 unreviewed deletion
        ->where('shell.quarantined', 1));

    // A reconcile finds another deletion…
    $found = Rows::submission(['deleted_at_source' => now()]);
    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page->where('admin.openIssueCount', 2)); // cached
    SourceDeletionDetected::dispatch($found, 'odk', 'uuid:found');
    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page->where('admin.openIssueCount', 3));

    // …and an administrator reviews it.
    DB::table('submissions')->where('id', $found)->update(['deletion_reviewed_at' => now(), 'deletion_reviewed_by' => $this->admin->id]);
    DeletionReviewed::dispatch($found, 'odk');
    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page->where('admin.openIssueCount', 2));
});

it('lapses the cached count after a minute anyway', function (): void {
    Rows::quarantined();
    expect(app(QuarantineQuery::class)->openCountCached())->toBe(1);
    Rows::quarantined();

    $this->travel(61)->seconds();

    expect(app(QuarantineQuery::class)->openCountCached())->toBe(2);
});

it('raises the banner when a round is open and no pull has succeeded for 24 hours', function (Closure $setup, bool $stale): void {
    configureOdk();
    $setup();

    $this->actingAs($this->admin)->get('/admin/rounds')->assertInertia(fn (Assert $page) => $page->where('admin.staleIngestion.stale', $stale));
})->with([
    'open round, last success 25 hours ago' => [function (): void {
        Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
        Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subHours(25), 'finished_at' => now()->subHours(25)]);
    }, true],
    'open round, never a successful pull' => [fn () => Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']), true],
    'open round, a success 23 hours ago' => [function (): void {
        Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
        Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subHours(23), 'finished_at' => now()->subHours(23)]);
    }, false],
    'no open round' => [fn () => Rows::round(['year' => 2026, 'quarter' => 1, 'window_start' => '2026-01-01', 'window_end' => '2026-03-31', 'status' => 'closed']), false],
    'only a backfill succeeded' => [function (): void {
        Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
        Rows::pullRun(['trigger_type' => 'backfill', 'outcome' => 'succeeded', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
    }, true],
]);

it('says which round is open and when the last success was', function (): void {
    $this->freezeSecond(); // the time is compared to the second
    configureOdk();
    Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
    Rows::pullRun(['outcome' => 'succeeded', 'started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2)]);

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('admin.staleIngestion.round', ['year' => 2026, 'quarter' => 4])
        ->where('admin.staleIngestion.hours', 24)
        ->where('admin.staleIngestion.sources', [['key' => 'odk', 'name' => config('edqa.sources.odk.name'), 'lastSuccessAt' => now()->subDays(2)->startOfSecond()->toIso8601String()]]));
});

it('names only the enabled sources that are behind: one healthy source does not hide another', function (): void {
    configureOdk();
    shellConfigureKobo();
    Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
    Rows::pullRun(['source_id' => Rows::sourceId('odk'), 'outcome' => 'succeeded', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('admin.staleIngestion.stale', true)
        ->where('admin.staleIngestion.sources', [['key' => 'kobo', 'name' => config('edqa.sources.kobo.name'), 'lastSuccessAt' => null]]));

    // Switched off, it is not expected to pull: no banner.
    DB::table('submission_sources')->where('key', 'kobo')->update(['is_enabled' => false]);
    app(SourceRegistry::class)->flush();
    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page->where('admin.staleIngestion.stale', false)->where('admin.staleIngestion.sources', []));
});

it('reads each source\'s pull history once per admin page, for the status bar and the banner together', function (): void {
    configureOdk();
    shellConfigureKobo();
    Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
    Rows::pullRun(['source_id' => Rows::sourceId('odk'), 'outcome' => 'succeeded', 'started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2)]);
    $this->actingAs($this->admin);

    DB::enableQueryLog();
    $this->get('/admin/rounds')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('admin.staleIngestion.sources.0.key', 'odk')
        ->where('admin.staleIngestion.sources.1.key', 'kobo'));
    $log = collect(DB::getQueryLog())->pluck('query');

    // Two reads a source (the latest finished run, the last success), two sources.
    expect($log->filter(fn (string $sql): bool => str_contains($sql, 'pull_runs'))->count())->toBe(4)
        ->and($log->filter(fn (string $sql): bool => str_contains($sql, 'from "submission_sources"'))->count())->toBeLessThanOrEqual(4);
});

it('leaves the banner to the status bar while ODK is not configured', function (): void {
    config(['edqa.sources.odk.url' => null]);
    Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);

    $this->actingAs($this->admin)->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->where('admin.staleIngestion.stale', false)
        ->where('shell.sources.0.key', 'odk')
        ->where('shell.sources.0.state', 'not_configured'));
});
