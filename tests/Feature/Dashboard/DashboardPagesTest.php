<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// ARCHITECTURE.md §9: six dashboard pages, one thin controller each, aggregators only; filters
// live in the URL and are resolved once per request (ShareDashboardContext).

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
    $this->f = ReportingFixture::build();
    $this->admin = User::factory()->withTwoFactor()->create();
});

const DASHBOARD_PAGES = [
    'home' => ['owners', 'trend', 'weakest'],
    'facility' => ['facilities'],
    'lga' => ['lgas'],
    'monitor' => ['owners', 'coverage', 'windows'],
    'visuals' => ['monthSlots', 'lgas'],
    'plan' => ['plan'],
];

it('sends /dashboard and / to the home page, keeping the filters', function (string $from): void {
    $this->actingAs($this->admin)->get("{$from}?year=2026&quarter=2")
        ->assertRedirect('/dashboard/home?year=2026&quarter=2');
})->with(['/dashboard', '/']);

it('renders each page with the shared context, its heavy blocks deferred', function (string $page, array $deferred): void {
    $this->actingAs($this->admin)->get("/dashboard/{$page}")
        ->assertOk()
        ->assertInertia(function (Assert $inertia) use ($page, $deferred): void {
            $inertia->component("dashboard/{$page}")
                ->where('filters', ['year' => null, 'quarter' => null, 'lga' => null, 'level' => null, 'owner' => null])
                ->has('filterOptions.rounds', 2)
                ->has('filterOptions.lgas', 26) // 23 seeded + the fixture's three test LGAs
                ->has('filterOptions.levels', 3)
                ->has('filterOptions.owners', 2)
                ->where('summary.facilities', 7)
                ->where('summary.lgas', 3)
                ->has('summary.overall.display')
                ->has('summary.overall.bandLabel');
            foreach ($deferred as $prop) {
                $inertia->missing($prop);
            }
            $inertia->loadDeferredProps(function (Assert $reloaded) use ($deferred): void {
                foreach ($deferred as $prop) {
                    $reloaded->has($prop);
                }
            });
        });
})->with(fn (): array => array_map(fn (string $page, array $props): array => [$page, $props], array_keys(DASHBOARD_PAGES), DASHBOARD_PAGES));

it('round-trips valid filters through the URL untouched', function (): void {
    $this->actingAs($this->admin)->get('/dashboard/lga?year=2026&quarter=2&lga=AAA&level=primary&owner=public')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['year' => 2026, 'quarter' => 2, 'lga' => 'AAA', 'level' => 'primary', 'owner' => 'public'])
            ->where('summary.facilities', 1)); // F1: AAA, public, primary
});

it('drops invalid filters and redirects to the cleaned URL, keeping other parameters', function (string $query, string $clean): void {
    $this->actingAs($this->admin)->get("/dashboard/facility?{$query}")->assertRedirect("/dashboard/facility{$clean}");
})->with([
    'unknown LGA' => ['year=2026&lga=ZZZ', '?year=2026'],
    'year with no round' => ['year=2031&owner=private', '?owner=private'],
    'quarter without a year' => ['quarter=2&level=primary', '?level=primary'],
    'quarter with no round in that year' => ['year=2026&quarter=4', '?year=2026'],
    'bad level and owner' => ['level=clinic&owner=ngo', ''],
    'a page parameter survives' => ['lga=nowhere&page=2', '?page=2'],
]);

it('does not redirect for key order alone: Inertia reports URLs with the keys sorted', function (): void {
    $this->actingAs($this->admin)->get('/dashboard/facility?owner=public&year=2026')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.year', 2026)->where('filters.owner', 'public'));
});

it('lists only years and quarters that have rounds, the LGAs by name, and the enum labels', function (): void {
    $this->actingAs($this->admin)->get('/dashboard/home')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filterOptions.rounds', [['year' => 2026, 'quarter' => 2], ['year' => 2026, 'quarter' => 1]])
            ->where('filterOptions.lgas.0', ['code' => 'AAA', 'name' => 'Alpha'])
            ->where('filterOptions.levels.0', ['value' => 'primary', 'label' => 'Primary'])
            ->where('filterOptions.owners', [['value' => 'public', 'label' => 'Public'], ['value' => 'private', 'label' => 'Private']]));
});

it('shares the shell status with every signed-in page: assessments and ODK sync', function (): void {
    $this->actingAs($this->admin)->get('/dashboard/home')
        ->assertInertia(fn (Assert $page) => $page
            ->where('shell.assessments', 8) // seven Q2 visits and one Q1
            ->where('shell.sync.state', 'not_configured')
            ->where('shell.quarantined', 0));
});

it('reads the sync state from the latest pull runs', function (array $runs, string $state): void {
    config(['edqa.odk' => [...config('edqa.odk'), 'url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'x', 'project_id' => '7', 'form_id' => 'dqa']]);
    foreach ($runs as [$minutesAgo, $outcome]) {
        DB::table('odk_pull_runs')->insert(['trigger_type' => 'scheduled', 'started_at' => now()->subMinutes($minutesAgo + 1),
            'finished_at' => $outcome === null ? null : now()->subMinutes($minutesAgo), 'outcome' => $outcome, 'error' => $outcome === 'failed' ? 'HTTP 503' : null]);
    }
    Rows::quarantined();

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn (Assert $page) => $page
            ->where('shell.sync.state', $state)
            ->where('shell.quarantined', 1));
})->with([
    'recent success' => [[[4, 'succeeded']], 'ok'],
    'stale success' => [[[45, 'succeeded']], 'stale'],
    'last pull failed' => [[[90, 'succeeded'], [3, 'failed']], 'failed'],
    'running after a success' => [[[10, 'succeeded'], [0, null]], 'ok'],
]);

it('has an Administration landing page', function (): void {
    $this->actingAs($this->admin)->get('/admin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/index'));
});
