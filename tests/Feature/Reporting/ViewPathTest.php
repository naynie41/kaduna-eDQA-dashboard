<?php

declare(strict_types=1);

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Reporting\Aggregators\CoverageAggregator;
use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\QuarterTrendAggregator;
use App\Domain\Reporting\Aggregators\WeakestLgasAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Support\RoundAggregatesView;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\RandomReportingDataset;

// Aggregators read round_aggregates when the filter maps onto view rows (one round, a scope,
// an owner type, a level) and use their own SQL otherwise. Both paths must give the same answer.

/**
 * Deep comparison: floats within 1e-9, everything else exactly.
 */
function sameResult(mixed $a, mixed $b, string $path = 'result'): void
{
    if (is_float($a) || is_float($b)) {
        expect(is_float($a) && is_float($b) && abs($a - $b) <= 1e-9)->toBeTrue("{$path}: ".var_export($a, true).' vs '.var_export($b, true));

        return;
    }
    if (is_object($a) && ! $a instanceof UnitEnum) {
        expect($b)->toBeInstanceOf($a::class);
        foreach (get_object_vars($a) as $key => $value) {
            sameResult($value, $b->{$key}, "{$path}->{$key}");
        }

        return;
    }
    if (is_array($a)) {
        expect(array_keys($b))->toBe(array_keys($a), "{$path}: keys");
        foreach ($a as $key => $value) {
            sameResult($value, $b[$key], "{$path}[{$key}]");
        }

        return;
    }
    expect($b)->toBe($a, $path);
}

/** @return array<string, mixed> every view-capable aggregator's answer for the filter */
function viewCapableAnswers(ReportFilter $filter): array
{
    Cache::flush();

    return [
        'dimensions' => app(DimensionAggregator::class)->dimensions($filter),
        'owner split' => app(OwnerSplitAggregator::class)->split($filter),
        'lga table' => app(LgaTableAggregator::class)->rows($filter),
        'weakest' => app(WeakestLgasAggregator::class)->weakest($filter),
        'trend' => app(QuarterTrendAggregator::class)->trend($filter),
        'coverage' => app(CoverageAggregator::class)->coverage($filter),
    ];
}

it('gives identical results from the view and from SQL', function (array $query): void {
    RandomReportingDataset::build(4242);
    DB::select('select refresh_round_aggregates()');
    $filter = new ReportFilter(...$query);

    config(['edqa.aggregation.read_view' => false]);
    $sql = viewCapableAnswers($filter);
    config(['edqa.aggregation.read_view' => true]);
    $view = viewCapableAnswers($filter);

    sameResult($sql, $view);
})->with([
    'latest round' => [[]],
    'one round' => [['year' => 2026, 'quarter' => 1]],
    'a year' => [['year' => 2026]],
    'owner' => [['year' => 2026, 'quarter' => 2, 'owner' => Ownership::Private]],
    'level' => [['year' => 2026, 'quarter' => 2, 'level' => FacilityLevel::Secondary]],
    'owner + level' => [['year' => 2026, 'quarter' => 2, 'owner' => Ownership::Public, 'level' => FacilityLevel::Primary]],
    'lga' => [['year' => 2026, 'quarter' => 2, 'lga' => 'BBB']],
    'private-only lga' => [['year' => 2026, 'quarter' => 2, 'lga' => 'PRV', 'owner' => Ownership::Public]],
]);

it('really reads the view when it can', function (): void {
    RandomReportingDataset::build(5);
    DB::select('select refresh_round_aggregates()');
    // Change the source data without refreshing: only the view still has the old numbers.
    DB::table('assessment_scores')->update(['score' => 0]);
    $filter = new ReportFilter(year: 2026, quarter: 2);

    config(['edqa.aggregation.read_view' => true]);
    Cache::flush();
    $fromView = app(DimensionAggregator::class)->dimensions($filter)->overall->value;
    config(['edqa.aggregation.read_view' => false]);
    Cache::flush();
    $fromSql = app(DimensionAggregator::class)->dimensions($filter)->overall->value;

    expect($fromView)->toBeGreaterThan(0.0)->and($fromSql)->toBe(0.0);
});

it('falls back to SQL while the view has never been populated', function (): void {
    RandomReportingDataset::build(6);
    DB::statement('DROP MATERIALIZED VIEW round_aggregates');
    DB::statement((string) file_get_contents(database_path('sql/round_aggregates_v3.sql')));  // WITH NO DATA
    config(['edqa.aggregation.read_view' => true]);

    expect(app(RoundAggregatesView::class)->usable())->toBeFalse()
        ->and(app(DimensionAggregator::class)->dimensions(new ReportFilter(year: 2026, quarter: 2))->overall->value)->not->toBeNull();
});
