<?php

declare(strict_types=1);

use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;

// Home (ARCHITECTURE.md §9.2.1): every number from an aggregator, rendered as sent.

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    $this->admin = User::factory()->withTwoFactor()->create();
});

/** @return array<string, mixed> the value as the JSON response carries it */
function asJson(mixed $value): array
{
    return json_decode((string) json_encode($value), true);
}

it('takes the hero and the dimensions from the aggregators, with the rule version thresholds', function (): void {
    $filter = new ReportFilter(year: 2026, quarter: 2, lga: 'AAA');
    $hero = asJson(app(StateSummaryAggregator::class)->summary($filter));
    $dimensions = asJson(app(DimensionAggregator::class)->dimensions($filter));

    $this->actingAs($this->admin)->get('/dashboard/home?year=2026&quarter=2&lga=AAA')
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/home')
            ->where('hero', $hero)
            ->where('hero.strongAt', 90)
            ->where('hero.weakBelow', 70)
            ->where('dimensions', $dimensions));
});

it('trends every round whatever the year and quarter, respecting the other filters, and marks the selected round', function (): void {
    $this->actingAs($this->admin)->get('/dashboard/home?year=2026&quarter=2&owner=public')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('trend')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('trend.selected', [$this->f->q2])
                ->has('trend.points', 2)
                ->where('trend.points.0.label', 'Q1 2026')
                ->where('trend.points.1.label', 'Q2 2026')
                // F1 is the only public facility in Q1 (scores 10): the owner filter applies.
                ->where('trend.points.0.overall.display', '10.0')
                ->where('trend.points.1.private.display', '—')
                ->has('trend.takeaway')));
});

it('selects the latest round in the trend when no round is chosen', function (): void {
    $this->actingAs($this->admin)->get('/dashboard/home')
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('trend.selected', [$this->f->q2])->has('trend.points', 2)));
});

it('lists the weakest LGAs, and the weakest wards once an LGA is chosen', function (): void {
    $this->actingAs($this->admin)->get('/dashboard/home')
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('weakest.scope', 'lgas')
                ->has('weakest.rows', 3)
                ->where('weakest.rows.0.name', 'Bravo'))); // (100 + 52) / 2 = 76, the lowest of the three

    // Alpha's wards, lowest first: F2 (70), F1 (80), F3 (86.7).
    $this->actingAs($this->admin)->get('/dashboard/home?lga=AAA')
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('weakest.scope', 'wards')
                ->where('weakest.rows.0.name', 'Ward F2')
                ->where('weakest.rows.1.name', 'Ward F1')
                ->where('weakest.rows.2.name', 'Ward F3')));
});

it('says the trend takeaway in words, from the aggregator', function (): void {
    $this->actingAs($this->admin)->get('/dashboard/home')
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('trend.takeaway', fn (string $text): bool => str_starts_with($text, 'Q2 2026: '))));
});
