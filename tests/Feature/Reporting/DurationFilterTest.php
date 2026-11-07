<?php

declare(strict_types=1);

use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\MonthSlotAggregator;
use App\Domain\Reporting\Aggregators\QuarterTrendAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReportingFixture;

// The Duration filter (D-75): a quarter, or one month. A month switches every figure to that month:
// each facility's dimensions are its scores in that month slot, and its overall is the mean of the
// month's dimensions that have a score. Worked out by hand from ReportingFixture, where only F3's
// availability changes from month to month (95, 85, 75; every other facility scores the same each
// month).

const MONTH_DELTA = 0.0001;

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    DB::select('select refresh_round_aggregates()'); // the view must not answer for a month
});

it('reads a month from the URL: it needs a year, implies its quarter and slot, and links back as the month', function (): void {
    $june = ReportFilter::fromArray(['year' => '2026', 'month' => '6'], ReportAudience::Admin);
    expect([$june->year, $june->quarter, $june->month, $june->monthSlot()])->toBe([2026, 2, 6, 3])
        ->and($june->toQuery())->toBe(['year' => 2026, 'month' => 6])
        ->and($june->cacheKey())->not->toBe(ReportFilter::fromArray(['year' => '2026', 'quarter' => '2'], ReportAudience::Admin)->cacheKey())
        ->and(ReportFilter::fromArray(['year' => '2026', 'quarter' => '2'], ReportAudience::Admin)->monthSlot())->toBeNull();

    expect(fn () => ReportFilter::fromArray(['month' => '6'], ReportAudience::Admin))->toThrow(ValidationException::class)
        ->and(fn () => ReportFilter::fromArray(['year' => '2026', 'month' => '13'], ReportAudience::Admin))->toThrow(ValidationException::class);

    // A bookmark with a month of a quarter that has no round falls back to all, like a quarter.
    $rounds = [['year' => 2026, 'quarter' => 1], ['year' => 2026, 'quarter' => 2]];
    expect(ReportFilter::resolve(['year' => '2026', 'month' => '5'], $rounds, [])->month)->toBe(5)
        ->and(ReportFilter::resolve(['year' => '2026', 'month' => '8'], $rounds, [])->month)->toBeNull()
        ->and(ReportFilter::resolve(['year' => '2026', 'month' => '8'], $rounds, [])->quarter)->toBeNull();
});

it('switches the dimensions and overall to the month: June', function (): void {
    $june = ReportFilter::fromArray(['year' => '2026', 'month' => '6'], ReportAudience::Admin);
    $dimensions = app(DimensionAggregator::class)->dimensions($june);
    $summary = app(StateSummaryAggregator::class)->summary($june);

    // Availability in June: (90 + 60 + 75 + 100 + 40 + 77 + 73) / 7; consistency and validity are the quarter's.
    expect($dimensions->availability->value)->toEqualWithDelta(515 / 7, MONTH_DELTA)
        // Each facility's June overall (F3: (75 + 90 + 85) / 3), then their mean.
        ->and($summary->overall->value)->toEqualWithDelta((80 + 70 + 250 / 3 + 100 + 52 + 88 + 74) / 7, MONTH_DELTA)
        ->and($summary->facilitiesVisited)->toBe(7);
});

it('keeps the quarter as before when no month is chosen', function (): void {
    $q2 = ReportFilter::fromArray(['year' => '2026', 'quarter' => '2'], ReportAudience::Admin);

    expect(app(DimensionAggregator::class)->dimensions($q2)->availability->value)->toEqualWithDelta(525 / 7, MONTH_DELTA)
        ->and(app(StateSummaryAggregator::class)->summary($q2)->overall->value)->toEqualWithDelta(550.666667 / 7, MONTH_DELTA);
});

it('switches the LGA table to the month too: Alpha in April', function (): void {
    $april = ReportFilter::fromArray(['year' => '2026', 'month' => '4'], ReportAudience::Admin);
    $alpha = collect(app(LgaTableAggregator::class)->rows($april))->firstWhere('name', 'Alpha');

    // F1 80, F2 70, F3 (95 + 90 + 85) / 3 = 90.
    expect($alpha->overall->value)->toEqualWithDelta((80 + 70 + 90) / 3, MONTH_DELTA);
});

it('breaks the quarter down month by month, each with its three dimensions and overall', function (): void {
    $points = array_values(array_filter(
        app(MonthSlotAggregator::class)->series(ReportFilter::fromArray(['year' => '2026', 'quarter' => '2'], ReportAudience::Admin)),
        fn ($p): bool => $p->roundId === $this->f->q2,
    ));

    expect(array_map(fn ($p): int => $p->month, $points))->toBe([4, 5, 6])
        ->and($points[0]->availability->value)->toEqualWithDelta(535 / 7, MONTH_DELTA)
        ->and($points[2]->availability->value)->toEqualWithDelta(515 / 7, MONTH_DELTA)
        ->and($points[0]->overall->value)->toEqualWithDelta((80 + 70 + 90 + 100 + 52 + 88 + 74) / 7, MONTH_DELTA);
});

it('keeps the trend quarterly, with each quarter\'s dimensions, whatever month is chosen', function (): void {
    $points = app(QuarterTrendAggregator::class)->trend(ReportFilter::fromArray(['year' => '2026', 'month' => '6'], ReportAudience::Admin));
    $q2 = collect($points)->firstWhere('roundId', $this->f->q2);

    expect($q2->availability->value)->toEqualWithDelta(525 / 7, MONTH_DELTA)
        ->and($q2->consistency->value)->not->toBeNull()
        ->and($q2->validity->value)->not->toBeNull()
        ->and($q2->overall->value)->toEqualWithDelta(550.666667 / 7, MONTH_DELTA);
});
