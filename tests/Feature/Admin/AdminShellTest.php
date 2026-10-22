<?php

declare(strict_types=1);

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
    $this->admin = User::factory()->withTwoFactor()->create();
});

const ADMIN_PAGES = [
    'odk' => 'admin/odk',
    'data-issues' => 'admin/data-issues',
    'rounds' => 'admin/rounds',
    'facilities' => 'admin/facilities',
    'scoring-rules' => 'admin/scoring-rules',
];

function configureOdk(): void
{
    config(['edqa.odk' => [...config('edqa.odk'), 'url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'x', 'project_id' => '7', 'form_id' => 'dqa']]);
}

it('redirects /admin to the ODK pull page', function (): void {
    $this->actingAs($this->admin)->get('/admin')->assertRedirect('/admin/odk');
});

it('serves each admin page with the admin props and strings, and no dashboard filters', function (string $path, string $component): void {
    $this->actingAs($this->admin)->get("/admin/{$path}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('admin.openQuarantineCount', 0)
            ->where('admin.staleIngestion.stale', false)
            ->has('translations.admin.tabs')
            ->missing('filters')
            ->has('shell.sync'));
})->with(array_map(fn (string $component, string $path): array => [$path, $component], ADMIN_PAGES, array_keys(ADMIN_PAGES)));

it('keeps every admin page behind the sign-in', function (string $path): void {
    $this->get("/admin/{$path}")->assertRedirect('/login');
})->with(array_keys(ADMIN_PAGES));

it('does not compute the admin props for a dashboard page', function (): void {
    $this->seed(LgaSeeder::class);

    $this->actingAs($this->admin)->get('/dashboard/home')
        ->assertInertia(fn (Assert $page) => $page->where('admin', null)->missing('translations.admin'));
});

it('shows the open quarantine count, cached, and forgets it when a record is quarantined or resolved', function (): void {
    Rows::quarantined();
    Rows::quarantined();
    Rows::quarantined(['status' => 'resolved', 'resolution' => 'rejected', 'resolved_at' => now()]);

    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page->where('admin.openQuarantineCount', 2)->where('shell.quarantined', 2));
    expect(Cache::get(QuarantineQuery::OPEN_COUNT_KEY))->toBe(2);

    // A row written another way is not seen until the cache is forgotten (or lapses after 60 s)…
    $third = Rows::quarantined();
    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page->where('admin.openQuarantineCount', 2));

    // …which a quarantine does,
    SubmissionQuarantined::dispatch($third, 1);
    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page->where('admin.openQuarantineCount', 3));

    // and a resolution.
    DB::table('quarantined_records')->where('id', $third)->update(['status' => 'resolved']);
    QuarantineResolved::dispatch($third, null, QuarantineResolution::Rejected);
    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page->where('admin.openQuarantineCount', 2));
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

    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page
        ->where('admin.staleIngestion.round', ['year' => 2026, 'quarter' => 4])
        ->where('admin.staleIngestion.hours', 24)
        ->where('admin.staleIngestion.lastSuccessAt', now()->subDays(2)->startOfSecond()->toIso8601String()));
});

it('leaves the banner to the status bar while ODK is not configured', function (): void {
    config(['edqa.odk.url' => null]);
    Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);

    $this->actingAs($this->admin)->get('/admin/odk')->assertInertia(fn (Assert $page) => $page
        ->where('admin.staleIngestion.stale', false)
        ->where('shell.sync.state', 'not_configured'));
});
