<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;

// Visuals (ARCHITECTURE.md §9.2.5): the month-slot series with their takeaways, the LGA rankings,
// and the Overall / Public / Private view. Figures are ReportingFixture's.

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    $this->admin = User::factory()->withTwoFactor()->create();
});

/** Every prop after the deferred ones load. */
function visualsFor(object $test, string $query = ''): array
{
    $result = null;
    $test->actingAs($test->admin)->get("/dashboard/visuals{$query}")
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$result): void {
            $page->component('dashboard/visuals')
                ->has('ownerView')
                ->missing('monthSlots'); // deferred: numbers and skeletons paint first
            $initial = $page->toArray()['props'];
            // The deferred reload returns only the deferred props: merge them over the first load.
            $page->loadDeferredProps(function (Assert $reload) use (&$result, $initial): void {
                $result = [...$initial, ...$reload->toArray()['props']];
            });
        });

    return $result;
}

it('gives every quarter in view three month points, labelled with the real month and the quarter', function (): void {
    $points = visualsFor($this, '?year=2026')['monthSlots']['points'];

    expect(array_column($points, 'chartLabel'))->toBe([
        'January (Q1 2026)', 'February (Q1 2026)', 'March (Q1 2026)',
        'April (Q2 2026)', 'May (Q2 2026)', 'June (Q2 2026)',
    ])
        ->and($points[3]['availability']['value'])->toEqualWithDelta(535 / 7, 1e-6)
        ->and($points[3]['overall']['display'])->toBe('79.1');
});

it('says what each month chart shows, in words computed on the server', function (): void {
    $takeaways = visualsFor($this, '?year=2026')['monthSlots']['takeaways'];

    expect($takeaways)->toBe([
        1 => 'April 2026: overall 79.1, up 69.1 points on January 2026. Lowest dimension: Availability, at 76.4.',
        2 => 'May 2026: overall 78.7, up 68.7 points on February 2026. Lowest dimension: Availability, at 75.0.', // F3: 85 in slot 2
        3 => 'June 2026: overall 78.2, up 68.2 points on March 2026. Lowest dimension: Availability, at 73.6.',
    ]);
});

it('says the single quarter without a comparison', function (): void {
    expect(visualsFor($this, '?year=2026&quarter=2')['monthSlots']['takeaways'][1])
        ->toBe('April 2026: overall 79.1. Lowest dimension: Availability, at 76.4.');
});

it('narrows both blocks to one owner type with owner_view, independent of the owner filter', function (): void {
    $props = visualsFor($this, '?year=2026&quarter=2&owner_view=private');

    // F2 (60, 50, 100) and F6 (77, 88, 99): availability 68.5.
    expect($props['ownerView'])->toBe(['value' => 'private', 'locked' => null])
        ->and($props['monthSlots']['points'][0]['availability']['display'])->toBe('68.5')
        ->and(array_column(array_filter($props['lgas'], fn (array $l): bool => $l['facilities'] > 0), 'code'))->toBe(['AAA', 'CCC'])
        ->and($props['filters']['owner'])->toBeNull();
});

it('locks the view to the owner filter when one is set, whatever owner_view asks', function (): void {
    $props = visualsFor($this, '?year=2026&quarter=2&owner=public&owner_view=private');

    expect($props['ownerView'])->toBe(['value' => 'public', 'locked' => 'public'])
        ->and($props['monthSlots']['points'][0]['availability']['display'])->toBe('79.6'); // F1 F3 F4 F5 F7: 398 / 5
});

it('reads anything else in owner_view as Overall', function (string $value): void {
    expect(visualsFor($this, "?year=2026&quarter=2&owner_view={$value}")['ownerView'])->toBe(['value' => 'all', 'locked' => null]);
})->with(['all', 'everyone', 'PUBLIC%27--']);
