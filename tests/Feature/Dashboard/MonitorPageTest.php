<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;

// Monitor (ARCHITECTURE.md §9.2.4): the props as the page receives them, from OwnerSplitAggregator,
// CoverageAggregator and AssessmentWindowAggregator. The figures are ReportingFixture's.

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    $this->admin = User::factory()->withTwoFactor()->create();
});

/** The deferred props after their load, for a query string. */
function monitorFor(object $test, string $query = ''): array
{
    $result = null;
    $test->actingAs($test->admin)->get("/dashboard/monitor{$query}")
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$result): void {
            $page->component('dashboard/monitor')
                ->missing('coverage') // deferred: the shell renders first
                ->loadDeferredProps(function (Assert $reload) use (&$result): void {
                    $result = $reload->toArray()['props'];
                });
        });

    return $result;
}

it('gives facilities visited per LGA, most first, and the state mean as a number and as text', function (): void {
    $coverage = monitorFor($this, '?year=2026&quarter=2')['coverage'];

    expect(array_map(fn (array $l): array => [$l['name'], $l['facilities']], $coverage['perLga']))->toBe([
        ['Alpha', 3], ['Bravo', 2], ['Charlie', 2],
    ])
        ->and($coverage['stateMean'])->toEqualWithDelta(7 / 3, 1e-9)
        // Rounded on the server, never in a component.
        ->and($coverage['stateMeanDisplay'])->toBe('2.3');
});

it('gives coverage by level in tier order, each with its overall as a ScoreValue', function (): void {
    $levels = monitorFor($this, '?year=2026&quarter=2')['coverage']['byLevel'];

    expect(array_map(fn (array $l): array => [$l['level'], $l['facilities']], $levels))->toBe([
        ['primary', 4], ['secondary', 2], ['tertiary', 1],
    ])
        ->and($levels[1]['overall'])->toMatchArray(['display' => '87.3', 'band' => 'acceptable']);
});

it('gives each round in view its window, first and last visit and assessments recorded', function (): void {
    $windows = monitorFor($this, '?year=2026')['windows'];

    expect(array_map(fn (array $w): array => [$w['label'], $w['windowStart'], $w['windowEnd'], $w['firstVisit'], $w['lastVisit'], $w['assessments']], $windows))->toBe([
        ['Q1 2026', '2026-01-01', '2026-03-31', '2026-02-10', '2026-02-10', 1],
        ['Q2 2026', '2026-04-01', '2026-06-30', '2026-04-10', '2026-06-28', 7],
    ]);
});

it('follows the filters: an owner narrows every block', function (): void {
    $props = monitorFor($this, '?year=2026&quarter=2&owner=private');

    expect(array_sum(array_column($props['coverage']['perLga'], 'facilities')))->toBe(2) // F2, F6
        ->and($props['coverage']['stateMeanDisplay'])->toBe('0.7')
        ->and($props['windows'][0]['assessments'])->toBe(2)
        ->and($props['owners']['private']['facilities'])->toBe(2)
        ->and($props['owners']['public']['facilities'])->toBe(0);
});

it('says when nothing is in view: no visits, no mean', function (): void {
    $props = monitorFor($this, '?year=2026&quarter=2&lga=CCC&level=tertiary');

    expect($props['coverage']['perLga'])->toBe([['lgaId' => $this->f->lgas['CCC'], 'code' => 'CCC', 'name' => 'Charlie', 'facilities' => 0]])
        ->and($props['coverage']['stateMeanDisplay'])->toBe('0.0')
        ->and($props['coverage']['byLevel'])->toBe([])
        ->and($props['windows'][0])->toMatchArray(['firstVisit' => null, 'lastVisit' => null, 'assessments' => 0]);
});
