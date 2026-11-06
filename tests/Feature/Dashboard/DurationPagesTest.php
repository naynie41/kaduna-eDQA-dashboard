<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;

// The Duration filter on the pages (D-75), the month-by-month breakdown on Home, the quarterly
// view on Visuals and the all-facilities overall on Monitor. Numbers from ReportingFixture.

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    $this->admin = User::factory()->create();
});

it('keeps a month in the filters, and switches the page to it', function (): void {
    $this->actingAs($this->admin)->get('/?year=2026&month=6')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.month', 6)
            ->where('filters.quarter', 2)
            // June: (80 + 70 + 83.33 + 100 + 52 + 88 + 74) / 7 = 78.19.
            ->where('hero.overall.display', '78.2'));
});

it('offers every month of every quarter with a round in the Duration list', function (): void {
    $this->actingAs($this->admin)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('filterOptions.rounds', fn ($rounds): bool => collect($rounds)->contains(fn ($r): bool => $r['year'] === 2026 && $r['quarter'] === 2)));
});

it('breaks the quarter in view down month by month on Home: each month\'s three dimensions and overall', function (): void {
    $this->actingAs($this->admin)->get('/?year=2026&quarter=2')
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->has('months', 3)
            ->where('months.0.month', 4)
            ->where('months.2.month', 6)
            // April availability (90 + 60 + 95 + 100 + 40 + 77 + 73) / 7 = 76.43; June 73.57.
            ->where('months.0.availability.display', '76.4')
            ->where('months.2.availability.display', '73.6')
            ->where('months.0.overall.display', '79.1')));
});

it('gives Visuals each quarter\'s three dimensions and overall, for the quarterly view', function (): void {
    $this->actingAs($this->admin)->get('/visuals?year=2026&quarter=2')
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->has('quarters', 2)
            ->where('quarters.1.label', 'Q2 2026')
            ->where('quarters.1.availability.display', '75.0')
            ->where('quarters.1.overall.display', '78.7')));
});

it('shows Monitor an overall for all facilities, public and private together, narrowed by the Owner filter', function (string $query, int $facilities, string $overall): void {
    $this->actingAs($this->admin)->get("/monitor?year=2026&quarter=2{$query}")
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('overall.summary.facilitiesVisited', $facilities)
            ->where('overall.summary.overall.display', $overall)
            ->has('overall.dimensions.availability')));
})->with([
    'public and private' => ['', 7, '78.7'],
    'private only' => ['&owner=private', 2, '79.0'], // F2 70, F6 88
]);
