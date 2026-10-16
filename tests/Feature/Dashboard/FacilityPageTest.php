<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;

// Facility view (ARCHITECTURE.md §9.2.2): server-side sorting and paging, weakest first.

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    $this->admin = User::factory()->withTwoFactor()->create();
});

/** The facilities prop after its deferred load, for a query string. */
function facilitiesFor(object $test, string $query): array
{
    $result = null;
    $test->actingAs($test->admin)->get("/dashboard/facility{$query}")
        ->assertOk()
        // A full closure, not fn(): an arrow function captures $result by value, so the inner
        // reference would point at its copy.
        ->assertInertia(function (Assert $page) use (&$result): void {
            $page->loadDeferredProps(function (Assert $reload) use (&$result): void {
                $result = $reload->toArray()['props']['facilities'];
            });
        });

    return $result;
}

it('lists facilities weakest first by overall by default, 50 a page', function (): void {
    $facilities = facilitiesFor($this, '');

    expect($facilities['metric'])->toBe('overall')
        ->and($facilities['sort'])->toBe(['column' => 'score', 'direction' => 'asc'])
        ->and($facilities['page']['perPage'])->toBe(50)
        ->and($facilities['page']['total'])->toBe(7)
        ->and(array_column($facilities['page']['rows'], 'name'))->toBe([
            'Facility F5', 'Facility F2', 'Facility F7', 'Facility F1', 'Facility F3', 'Facility F6', 'Facility F4',
        ]);
});

it('switches the metric: month cells and the score follow it, still weakest first', function (string $metric, string $weakest): void {
    $facilities = facilitiesFor($this, "?metric={$metric}");

    expect($facilities['metric'])->toBe($metric)
        ->and($facilities['page']['rows'][0]['name'])->toBe($weakest);
})->with([
    'availability' => ['availability', 'Facility F5'], // 40
    'consistency' => ['consistency', 'Facility F2'],  // 50
    'validity' => ['validity', 'Facility F5'],         // 61
]);

it('sorts on a whitelisted column either way', function (): void {
    $byName = facilitiesFor($this, '?sort=facility&dir=desc');

    expect($byName['sort'])->toBe(['column' => 'facility', 'direction' => 'desc'])
        ->and($byName['page']['rows'][0]['name'])->toBe('Facility F7');
});

it('ignores anything else in sort, dir and metric: no SQL reaches the query, the default applies', function (string $query): void {
    $before = DB::table('facilities')->count();
    $facilities = facilitiesFor($this, $query);

    expect($facilities['sort'])->toBe(['column' => 'score', 'direction' => 'asc'])
        ->and($facilities['metric'])->toBe('overall')
        ->and($facilities['page']['rows'])->toHaveCount(7)
        ->and(DB::table('facilities')->count())->toBe($before);
})->with([
    'statement in sort' => ['?sort='.urlencode('name; DROP TABLE facilities; --')],
    'subquery in sort' => ['?sort='.urlencode('(SELECT pg_sleep(5))')],
    'column name not on the list' => ['?sort=password'],
    'direction' => ['?dir='.urlencode('asc; DELETE FROM facilities')],
    'metric' => ['?metric='.urlencode('overall) UNION SELECT 1 --')],
    'arrays' => ['?sort[]=facility&dir[]=desc&metric[]=validity'],
]);

it('caps the page size at 50 whatever is asked for, and pages past the end are empty, not an error', function (): void {
    $big = facilitiesFor($this, '?per_page=100000');
    $past = facilitiesFor($this, '?page=99');

    expect($big['page']['perPage'])->toBe(50)
        ->and($past['page']['rows'])->toBe([])
        ->and($past['page']['total'])->toBe(7)
        ->and($past['page']['page'])->toBe(99);
});

it('lists the quarters in view, for the month headings', function (): void {
    expect(facilitiesFor($this, '?year=2026&quarter=2')['quarters'])->toBe([2]);
});
