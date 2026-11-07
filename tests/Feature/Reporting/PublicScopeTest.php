<?php

declare(strict_types=1);

use App\Domain\Reporting\Aggregators\AssessmentWindowAggregator;
use App\Domain\Reporting\Aggregators\CoverageAggregator;
use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\FacilityScoresQuery;
use App\Domain\Reporting\Aggregators\ImplementationPlanAggregator;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\MonthSlotAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\QuarterTrendAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\Aggregators\WeakestLgasAggregator;
use App\Domain\Reporting\Aggregators\WeakestWardsAggregator;
use App\Domain\Reporting\DTOs\FacilitySort;
use App\Domain\Reporting\DTOs\LgaRow;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Reporting\Queries\FilterOptionsQuery;
use App\Domain\Reporting\Support\RoundSelection;
use App\Models\User;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;

// SECURITY.md §3, D-77: public data is closed rounds only. The fixture has 2026 Q1 closed (F1 alone,
// 10 everywhere) and 2026 Q2 open (all seven facilities). A visitor sees Q1 only; an administrator
// (the admin preview) sees both. config('edqa.public.show_open_round') widens the public view.

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
});

function audience(ReportAudience $audience, array $query = []): ReportFilter
{
    return ReportFilter::fromArray($query, $audience);
}

/**
 * Each dashboard aggregator, reduced to what tells the rounds apart: the round ids it covers, or the
 * facilities it counts.
 *
 * @return array<string, array{0: Closure(ReportFilter): mixed, 1: mixed, 2: mixed}> name => [read, public, admin]
 */
function aggregatorReads(): array
{
    return [
        'StateSummary (facilities)' => [fn (ReportFilter $f) => app(StateSummaryAggregator::class)->summary($f)->facilitiesVisited, 1, 7],
        'StateSummary (overall)' => [fn (ReportFilter $f) => app(StateSummaryAggregator::class)->summary($f)->overall->display, '10.0', '78.7'],
        'Dimension (availability)' => [fn (ReportFilter $f) => app(DimensionAggregator::class)->dimensions($f)->availability->display, '10.0', '75.0'],
        'OwnerSplit (facilities)' => [fn (ReportFilter $f) => [app(OwnerSplitAggregator::class)->split($f)->public->facilities, app(OwnerSplitAggregator::class)->split($f)->private->facilities], [1, 0], [5, 2]],
        'QuarterTrend (rounds)' => [fn (ReportFilter $f) => array_map(fn ($p): string => $p->label, app(QuarterTrendAggregator::class)->trend($f)), ['Q1 2026'], ['Q1 2026', 'Q2 2026']],
        'WeakestLgas (LGAs)' => [fn (ReportFilter $f) => array_map(fn ($l): string => $l->code, app(WeakestLgasAggregator::class)->weakest($f)), ['AAA'], ['BBB', 'AAA', 'CCC']],
        'WeakestWards in Alpha (wards)' => [fn (ReportFilter $f) => count(app(WeakestWardsAggregator::class)->weakest(ReportFilter::fromArray(['lga' => 'AAA'], $f->audience))), 1, 3],
        'MonthSlot (rounds)' => [fn (ReportFilter $f) => array_values(array_unique(array_map(fn ($p): string => "Q{$p->quarter} {$p->year}", app(MonthSlotAggregator::class)->series($f)))), ['Q1 2026'], ['Q1 2026', 'Q2 2026']],
        'LgaTable (facilities)' => [fn (ReportFilter $f) => array_sum(array_map(fn (LgaRow $r): int => $r->facilities, app(LgaTableAggregator::class)->rows($f))), 1, 7],
        'Coverage (facilities)' => [fn (ReportFilter $f) => array_sum(array_map(fn ($c): int => $c->facilities, app(CoverageAggregator::class)->coverage($f)->perLga)), 1, 7],
        'AssessmentWindow (rounds)' => [fn (ReportFilter $f) => array_map(fn ($w): string => $w->label, app(AssessmentWindowAggregator::class)->windows($f)), ['Q1 2026'], ['Q1 2026', 'Q2 2026']],
        'FacilityScoresQuery (rows)' => [fn (ReportFilter $f) => app(FacilityScoresQuery::class)->paginate($f, FacilitySort::default())->total, 1, 7],
        'ImplementationPlan (round)' => [fn (ReportFilter $f) => app(ImplementationPlanAggregator::class)->plan($f)->round, 'Q1 2026', 'Q2 2026'],
    ];
}

// ---- Every aggregator

it('shows a visitor closed rounds only, in every aggregator', function (Closure $read, mixed $public): void {
    expect($read(audience(ReportAudience::Public)))->toBe($public);
})->with(fn (): array => array_map(fn (array $r): array => [$r[0], $r[1]], aggregatorReads()));

it('shows an administrator every round, in every aggregator', function (Closure $read, mixed $public, mixed $admin): void {
    expect($read(audience(ReportAudience::Admin)))->toBe($admin);
})->with(fn (): array => aggregatorReads());

it('widens the public view to the open round when edqa.public.show_open_round is on', function (Closure $read, mixed $public, mixed $admin): void {
    expect(config('edqa.public.show_open_round'))->toBeFalse();
    config(['edqa.public.show_open_round' => true]);

    expect($read(audience(ReportAudience::Public)))->toBe($admin);
})->with(fn (): array => aggregatorReads());

it('treats a filter built without an audience as public', function (): void {
    expect((new ReportFilter)->audience)->toBe(ReportAudience::Public)
        ->and(ReportFilter::fromArray([])->audience)->toBe(ReportAudience::Public)
        ->and(app(StateSummaryAggregator::class)->summary(new ReportFilter)->facilitiesVisited)->toBe(1);
});

it('keeps the audience through every derived filter', function (): void {
    $admin = new ReportFilter(year: 2026, quarter: 2, month: 5, audience: ReportAudience::Admin);

    expect($admin->withoutMonth()->audience)->toBe(ReportAudience::Admin)
        ->and($admin->withoutRound()->audience)->toBe(ReportAudience::Admin)
        ->and($admin->withOwner(null)->audience)->toBe(ReportAudience::Admin)
        ->and($admin->withoutLga()->audience)->toBe(ReportAudience::Admin);
});

it('gives an open round asked for by name nothing to a visitor', function (): void {
    $open = audience(ReportAudience::Public, ['year' => '2026', 'quarter' => '2']);

    expect(app(RoundSelection::class)->current($open))->toBe([])
        ->and(app(StateSummaryAggregator::class)->summary($open)->facilitiesVisited)->toBe(0);
});

it('never counts a withdrawn assessment, even in a closed round', function (): void {
    DB::table('assessments')->where('round_id', $this->f->q1)->update(['status' => 'withdrawn']);

    expect(app(StateSummaryAggregator::class)->summary(audience(ReportAudience::Public))->facilitiesVisited)->toBe(0);
});

// ---- Cache

it('keys the cache by audience, so the admin preview is never served to a visitor', function (): void {
    expect(audience(ReportAudience::Admin)->cacheKey())->not->toBe(audience(ReportAudience::Public)->cacheKey());

    // The admin preview computes and caches first; the visitor still gets closed rounds only.
    expect(app(StateSummaryAggregator::class)->summary(audience(ReportAudience::Admin))->facilitiesVisited)->toBe(7)
        ->and(app(StateSummaryAggregator::class)->summary(audience(ReportAudience::Public))->facilitiesVisited)->toBe(1)
        ->and(app(RoundSelection::class)->series(audience(ReportAudience::Public)))->toHaveCount(1)
        ->and(app(FilterOptionsQuery::class)->get(ReportAudience::Admin)['rounds'])->toHaveCount(2)
        ->and(app(FilterOptionsQuery::class)->get(ReportAudience::Public)['rounds'])->toHaveCount(1);
});

// ---- Filter options and the URL

it('offers a visitor the closed rounds only in the filter bar', function (): void {
    expect(app(FilterOptionsQuery::class)->get(ReportAudience::Public)['rounds'])->toBe([['year' => 2026, 'quarter' => 1]])
        ->and(app(FilterOptionsQuery::class)->get(ReportAudience::Admin)['rounds'])->toBe([['year' => 2026, 'quarter' => 2], ['year' => 2026, 'quarter' => 1]]);
});

it('falls back to the latest closed round when a public URL names an open round', function (string $query): void {
    $this->seed(LgaSeeder::class);

    $this->get("/?{$query}")->assertRedirect('/');
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.year', null)
        ->where('filterOptions.rounds', [['year' => 2026, 'quarter' => 1]])
        ->where('summary.facilities', 1)
        ->where('unpublished', false));
})->with(['year=2026&quarter=2', 'year=2026&month=5', 'year=2027', 'year=2026&quarter=9']);

it('keeps a closed-round filter from a public URL', function (): void {
    $this->get('/lgas?year=2026&quarter=1')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.year', 2026)
        ->where('filters.quarter', 1));
});

it('lets the admin preview pick the open round on the public pages', function (): void {
    $this->actingAs(User::factory()->create())->get('/?year=2026&quarter=2')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.quarter', 2)
        ->has('filterOptions.rounds', 2)
        ->where('summary.facilities', 7));
});

// ---- Nothing published yet

it('says "No published results yet" to a visitor while no round is closed', function (): void {
    DB::table('rounds')->where('id', $this->f->q1)->update(['status' => 'open']);

    $this->get('/monitor')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('unpublished', true)
        ->where('filterOptions.rounds', [])
        ->where('summary.facilities', 0));
    expect(__('dashboard.unpublished.title'))->toBe('No published results yet.');

    $this->actingAs(User::factory()->create())->get('/monitor')->assertInertia(fn (Assert $page) => $page->where('unpublished', false));
});

// ---- Who is who

it('treats a signed-in administrator as Admin and anyone else as Public', function (): void {
    expect(ReportAudience::of(null))->toBe(ReportAudience::Public)
        ->and(ReportAudience::of(User::factory()->create()))->toBe(ReportAudience::Admin)
        ->and(ReportAudience::of(User::factory()->create(['is_active' => false])))->toBe(ReportAudience::Public);
});
