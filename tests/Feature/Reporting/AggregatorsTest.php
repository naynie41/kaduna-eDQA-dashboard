<?php

declare(strict_types=1);

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
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
use App\Domain\Reporting\Enums\Metric;
use App\Domain\Reporting\Exceptions\UnsupportedWeighting;
use App\Domain\Scoring\Enums\Band;
use App\Domain\Scoring\Enums\Dimension;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportingFixture;

// Every expected number is computed by hand from the table in ReportingFixture.

const DELTA = 0.0001;

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
});

function filter(array $query = []): ReportFilter
{
    return ReportFilter::fromArray($query);
}

// ---- State summary (Home hero)

it('summarises the latest round: overall, visits, LGAs, strong and weak counts', function (): void {
    $s = app(StateSummaryAggregator::class)->summary(filter());

    // (80 + 70 + 86.6667 + 100 + 52 + 88 + 74) / 7
    expect($s->overall->value)->toEqualWithDelta(550.666667 / 7, DELTA)
        ->and($s->overall->display)->toBe('78.7')
        ->and($s->overall->headline)->toBe('79')
        ->and($s->facilitiesVisited)->toBe(7)
        ->and($s->lgasCovered)->toBe(3)
        ->and($s->facilitiesStrong)->toBe(1)   // F4 (100)
        ->and($s->facilitiesWeak)->toBe(1);    // F5 (52); F2 at exactly 70 is not under 70
});

it('applies each filter on its own and combined', function (array $query, float $overall, int $facilities): void {
    $s = app(StateSummaryAggregator::class)->summary(filter($query));

    expect($s->overall->value)->toEqualWithDelta($overall, DELTA)
        ->and($s->facilitiesVisited)->toBe($facilities);
})->with([
    'lga Bravo' => [['lga' => 'BBB'], 76.0, 2],
    'level primary' => [['level' => 'primary'], 81.0, 4],               // 80, 70, 100, 74
    'owner private' => [['owner' => 'private'], 79.0, 2],               // 70, 88
    'year and quarter' => [['year' => 2026, 'quarter' => 2], 550.666667 / 7, 7],
    'earlier closed round' => [['year' => 2026, 'quarter' => 1], 10.0, 1],
    'lga + level' => [['lga' => 'AAA', 'level' => 'primary'], 75.0, 2], // 80, 70
    'lga + owner + level' => [['lga' => 'AAA', 'owner' => 'public', 'level' => 'secondary'], 86.666667, 1],
]);

it('shows no data, not zero, when a filter matches nothing', function (): void {
    $s = app(StateSummaryAggregator::class)->summary(filter(['lga' => 'CCC', 'level' => 'tertiary']));

    expect($s->overall->value)->toBeNull()
        ->and($s->overall->display)->toBe('—')
        ->and($s->facilitiesVisited)->toBe(0)
        ->and($s->lgasCovered)->toBe(0);
});

// ---- Dimensions

it('gives the three dimensions and the overall, and names the weakest', function (): void {
    $d = app(DimensionAggregator::class)->dimensions(filter());

    expect($d->availability->value)->toEqualWithDelta(525 / 7, DELTA)    // 75
        ->and($d->consistency->value)->toEqualWithDelta(537 / 7, DELTA)  // 76.7143
        ->and($d->validity->value)->toEqualWithDelta(590 / 7, DELTA)     // 84.2857
        ->and($d->overall->value)->toEqualWithDelta(550.666667 / 7, DELTA)
        ->and($d->weakest)->toBe(Dimension::Availability);
});

// ---- Owner split

it('splits public from private, each an unweighted mean of its facilities', function (): void {
    $split = app(OwnerSplitAggregator::class)->split(filter());

    expect($split->public->overall->value)->toEqualWithDelta(392.666667 / 5, DELTA)  // 80, 86.67, 100, 52, 74
        ->and($split->public->facilities)->toBe(5)
        ->and($split->public->availability->value)->toEqualWithDelta((90 + 85 + 100 + 40 + 73) / 5, DELTA)
        ->and($split->private->overall->value)->toEqualWithDelta(79.0, DELTA)
        ->and($split->private->facilities)->toBe(2)
        ->and($split->private->validity->value)->toEqualWithDelta(99.5, DELTA);
});

// ---- LGA table

it('gives each LGA its facilities, dimensions, month slots, overall and band', function (): void {
    $rows = app(LgaTableAggregator::class)->rows(filter());

    expect(array_map(fn ($r) => $r->name, $rows))->toBe(['Alpha', 'Bravo', 'Charlie']);
    [$alpha, $bravo] = $rows;
    expect($alpha->facilities)->toBe(3)
        ->and($alpha->availability->value)->toEqualWithDelta(235 / 3, DELTA)
        ->and($alpha->consistency->value)->toEqualWithDelta(220 / 3, DELTA)
        ->and($alpha->validity->value)->toEqualWithDelta(85.0, DELTA)
        ->and($alpha->m1->value)->toEqualWithDelta(80.0, DELTA)         // 80, 70, 90
        ->and($alpha->m2->value)->toEqualWithDelta(78.888889, DELTA)    // 80, 70, 86.67
        ->and($alpha->m3->value)->toEqualWithDelta(77.777778, DELTA)    // 80, 70, 83.33
        ->and($alpha->overall->value)->toEqualWithDelta(78.888889, DELTA)
        ->and($alpha->overall->band)->toBe(Band::Review)
        ->and($bravo->overall->value)->toEqualWithDelta(76.0, DELTA);
});

it('never lists more than 23 LGAs, and lists only the filtered one', function (): void {
    expect(count(app(LgaTableAggregator::class)->rows(filter())))->toBeLessThanOrEqual(23)
        ->and(array_map(fn ($r) => $r->code, app(LgaTableAggregator::class)->rows(filter(['lga' => 'CCC']))))->toBe(['CCC']);
});

// ---- Facility table

it('lists facilities weakest first by default, 50 a page', function (): void {
    $page = app(FacilityScoresQuery::class)->paginate(filter(), FacilitySort::default());

    expect(array_map(fn ($r) => $r->code, $page->rows))->toBe(['KD-F5', 'KD-F2', 'KD-F7', 'KD-F1', 'KD-F3', 'KD-F6', 'KD-F4'])
        ->and($page->total)->toBe(7)
        ->and($page->perPage)->toBe(50)
        ->and($page->rows[0]->lga)->toBe('Bravo')
        ->and($page->rows[0]->ward)->toBe('Ward F5')
        ->and($page->rows[0]->ownership)->toBe(Ownership::Public)
        ->and($page->rows[0]->roundLabel)->toBe('Q2 2026')
        ->and($page->rows[0]->assessedOn)->toBe('2026-06-01');
});

it('switches the metric: month cells and the sorted score follow it', function (): void {
    $page = app(FacilityScoresQuery::class)->paginate(filter(), new FacilitySort(Metric::Availability, 'score', 'asc'));
    $f3 = collect($page->rows)->firstWhere('code', 'KD-F3');

    expect($page->rows[0]->code)->toBe('KD-F5')          // availability 40
        ->and($f3->m1->value)->toEqualWithDelta(95.0, DELTA)
        ->and($f3->m3->value)->toEqualWithDelta(75.0, DELTA)
        ->and($f3->score->value)->toEqualWithDelta(85.0, DELTA)
        ->and($f3->overall->value)->toEqualWithDelta(86.666667, DELTA);
});

it('sorts on any column, either way', function (string $column, string $direction, string $first): void {
    $page = app(FacilityScoresQuery::class)->paginate(filter(), new FacilitySort(Metric::Overall, $column, $direction));

    expect($page->rows[0]->code)->toBe($first);
})->with([
    ['score', 'desc', 'KD-F4'],
    ['facility', 'asc', 'KD-F1'],
    ['lga', 'desc', 'KD-F6'],          // Charlie; ties broken by facility name
    ['assessed', 'desc', 'KD-F7'],
    ['m3', 'asc', 'KD-F5'],
]);

it('pages through results', function (): void {
    $page = app(FacilityScoresQuery::class)->paginate(filter(), FacilitySort::default(), page: 2, perPage: 3);

    expect(array_map(fn ($r) => $r->code, $page->rows))->toBe(['KD-F1', 'KD-F3', 'KD-F6'])
        ->and($page->page)->toBe(2)
        ->and($page->total)->toBe(7);
});

it('refuses a sort column it does not know', function (): void {
    expect(fn () => new FacilitySort(Metric::Overall, 'name; DROP TABLE facilities', 'asc'))->toThrow(InvalidArgumentException::class);
});

// ---- Quarter trend and month slots

it('trends the overall, public and private by quarter, each round under its own version', function (): void {
    $trend = app(QuarterTrendAggregator::class)->trend(filter(['year' => 2026]));

    expect(array_map(fn ($p) => $p->label, $trend))->toBe(['Q1 2026', 'Q2 2026'])
        ->and($trend[0]->overall->value)->toEqualWithDelta(10.0, DELTA)        // F1 only, pinned v1
        ->and($trend[0]->private->value)->toBeNull()
        ->and($trend[1]->overall->value)->toEqualWithDelta(550.666667 / 7, DELTA)
        ->and($trend[1]->private->value)->toEqualWithDelta(79.0, DELTA);
});

it('gives each quarter × month slot × measure with the real month name', function (): void {
    $points = app(MonthSlotAggregator::class)->series(filter(['year' => 2026, 'quarter' => 2]));

    expect(array_map(fn ($p) => $p->label, $points))->toBe(['April 2026', 'May 2026', 'June 2026'])
        ->and($points[0]->month)->toBe(4)
        ->and($points[0]->availability->value)->toEqualWithDelta(535 / 7, DELTA)  // 90 60 95 100 40 77 73
        ->and($points[0]->overall->value)->toEqualWithDelta(554 / 7, DELTA)       // 80 70 90 100 52 88 74
        ->and($points[2]->availability->value)->toEqualWithDelta(515 / 7, DELTA); // F3 has 75 in slot 3
});

// ---- Coverage, assessment window, weakest LGAs

it('counts facilities per LGA against the state mean, and by level', function (): void {
    $coverage = app(CoverageAggregator::class)->coverage(filter());

    expect(array_map(fn ($r) => [$r->code, $r->facilities], $coverage->perLga))->toBe([['AAA', 3], ['BBB', 2], ['CCC', 2]])
        ->and($coverage->stateMean)->toEqualWithDelta(7 / 3, DELTA)
        ->and(array_map(fn ($l) => [$l->level, $l->facilities], $coverage->byLevel))->toBe([
            [FacilityLevel::Primary, 4], [FacilityLevel::Secondary, 2], [FacilityLevel::Tertiary, 1],
        ])
        ->and($coverage->byLevel[1]->overall->value)->toEqualWithDelta(87.333333, DELTA);
});

it('gives each round in view its window, first and last visit and count', function (): void {
    $windows = app(AssessmentWindowAggregator::class)->windows(filter(['year' => 2026]));

    expect(array_map(fn ($w) => [$w->label, $w->firstVisit, $w->lastVisit, $w->assessments], $windows))->toBe([
        ['Q1 2026', '2026-02-10', '2026-02-10', 1],
        ['Q2 2026', '2026-04-10', '2026-06-28', 7],
    ]);
});

it('lists the six weakest LGAs, lowest first, only those with data', function (): void {
    $weakest = app(WeakestLgasAggregator::class)->weakest(filter());

    expect(array_map(fn ($l) => $l->code, $weakest))->toBe(['BBB', 'AAA', 'CCC'])
        ->and($weakest[0]->overall->value)->toEqualWithDelta(76.0, DELTA)
        ->and($weakest[0]->facilities)->toBe(2);
});

// ---- Rules that hold for all of them

it('counts only accepted or flagged assessments', function (): void {
    DB::table('assessments')->where('facility_id', $this->f->facilities['F4'])->update(['status' => 'withdrawn']);

    expect(app(StateSummaryAggregator::class)->summary(filter())->facilitiesVisited)->toBe(6);
});

it('refuses the facility_count weighting until its meaning is settled (Q-27)', function (): void {
    config(['edqa.aggregation.weighting' => 'facility_count']);

    expect(fn () => app(StateSummaryAggregator::class)->summary(filter()))
        ->toThrow(UnsupportedWeighting::class);
});

it('runs a bounded number of queries whatever the filter, and none once cached', function (array $query): void {
    $run = function () use ($query): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $f = filter($query);
        app(StateSummaryAggregator::class)->summary($f);
        app(DimensionAggregator::class)->dimensions($f);
        app(OwnerSplitAggregator::class)->split($f);
        app(LgaTableAggregator::class)->rows($f);
        app(FacilityScoresQuery::class)->paginate($f, FacilitySort::default());
        app(QuarterTrendAggregator::class)->trend($f);
        app(MonthSlotAggregator::class)->series($f);
        app(CoverageAggregator::class)->coverage($f);
        app(AssessmentWindowAggregator::class)->windows($f);
        app(WeakestLgasAggregator::class)->weakest($f);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    Cache::flush();
    expect($run())->toBeLessThanOrEqual(40)   // ten aggregators: rounds, versions and 1–2 maths queries each
        ->and($run())->toBe(0);                // every aggregator answered from the round cache
})->with([
    'no filter' => [[]],
    'every filter' => [['year' => 2026, 'quarter' => 2, 'lga' => 'AAA', 'level' => 'primary', 'owner' => 'public']],
]);
