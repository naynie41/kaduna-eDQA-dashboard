<?php

declare(strict_types=1);

use App\Domain\Reporting\Aggregators\AssessmentWindowAggregator;
use App\Domain\Reporting\Aggregators\CoverageAggregator;
use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\FacilityScoresQuery;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\MonthSlotAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\QuarterTrendAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\Aggregators\WeakestLgasAggregator;
use App\Domain\Reporting\DTOs\FacilitySort;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ReportingFixture;

// The test suite caches in an array store, which never serializes. Redis and the database store
// do, and Laravel only unserializes the classes in cache.serializable_classes (anything else comes
// back as __PHP_Incomplete_Class). These run every aggregator through the file store, which
// serializes the same way, and compare the cached answer with the fresh one.

beforeEach(function (): void {
    config(['cache.default' => 'file', 'cache.stores.file.path' => storage_path('framework/cache/test-'.getmypid())]);
    Cache::store('file')->flush();
    ReportingFixture::build();
});

afterEach(function (): void {
    Cache::store('file')->flush();
});

it('returns the same answer from a serializing cache as when computed', function (Closure $answer): void {
    $fresh = $answer();
    $cached = $answer();

    expect($cached)->toEqual($fresh);
    expect(serialize($cached))->not->toContain('__PHP_Incomplete_Class');
})->with([
    'state summary' => fn () => app(StateSummaryAggregator::class)->summary(new ReportFilter),
    'dimensions' => fn () => app(DimensionAggregator::class)->dimensions(new ReportFilter),
    'owner split' => fn () => app(OwnerSplitAggregator::class)->split(new ReportFilter),
    'LGA table' => fn () => app(LgaTableAggregator::class)->rows(new ReportFilter),
    'weakest LGAs' => fn () => app(WeakestLgasAggregator::class)->weakest(new ReportFilter),
    'trend' => fn () => app(QuarterTrendAggregator::class)->trend(new ReportFilter),
    'month slots' => fn () => app(MonthSlotAggregator::class)->series(new ReportFilter),
    'coverage' => fn () => app(CoverageAggregator::class)->coverage(new ReportFilter),
    'assessment window' => fn () => app(AssessmentWindowAggregator::class)->windows(new ReportFilter),
    'facility page' => fn () => app(FacilityScoresQuery::class)->paginate(new ReportFilter(year: 2026), FacilitySort::default()),
]);

it('lets no Eloquent model out of the cache, and nothing outside the allow-list', function (): void {
    foreach (RoundCache::SERIALIZABLE as $class) {
        expect(is_subclass_of($class, Model::class))->toBeFalse($class)
            ->and(class_exists($class) || enum_exists($class))->toBeTrue($class);
    }

    expect(config('cache.serializable_classes'))->toBe(RoundCache::SERIALIZABLE);
});
