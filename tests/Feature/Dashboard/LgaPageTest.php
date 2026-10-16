<?php

declare(strict_types=1);

use App\Domain\Facility\Enums\Ownership;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Models\User;
use Database\Seeders\LgaSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// LGA view (ARCHITECTURE.md §9.2.3; CLAUDE.md hard rule 9: never more than 23 LGAs).

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create();
});

it('never shows more than 23 LGAs in the tab badge or the grid, whatever sits in quarantine', function (): void {
    ReportingFixture::build(); // its three test LGAs are in the lgas table too: 26 rows there
    // The live report's failure: blank, "NULL" and made-up LGAs. In quarantine they reach nothing.
    foreach (['', 'NULL', 'Kaduna Central', 'Zaria North'] as $i => $bad) {
        Rows::quarantined(['lga_ref' => $bad, 'instance_id' => "uuid:bad-lga-{$i}"]);
    }
    $rows = DB::table('lgas')->count();

    $this->actingAs($this->admin)->get('/dashboard/lga')
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.lgas', fn (int $lgas): bool => $lgas <= 23)
            ->has('tiles', $rows)
            ->loadDeferredProps(fn (Assert $reload) => $reload->has('lgas', $rows)));

    expect($rows)->toBe(26); // fixture only: production has exactly 23, and no insert path
});

it('gives the grid every LGA whatever the LGA filter, and the table only the chosen one', function (): void {
    ReportingFixture::build();

    $this->actingAs($this->admin)->get('/dashboard/lga?lga=AAA')
        ->assertInertia(fn (Assert $page) => $page
            ->has('tiles', 26)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('lgas', 1)
                ->where('lgas.0.code', 'AAA')));
});

it('takes the rows from the LGA table aggregator, and lists the rounds in view for the month headings', function (): void {
    $f = ReportingFixture::build();
    $rows = json_decode((string) json_encode(app(LgaTableAggregator::class)->rows(new ReportFilter(year: 2026, quarter: 2, owner: Ownership::Public))), true);

    $this->actingAs($this->admin)->get('/dashboard/lga?year=2026&quarter=2&owner=public')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rounds', [['year' => 2026, 'quarter' => 2]])
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('lgas', $rows)));

    $this->actingAs($this->admin)->get('/dashboard/lga?year=2026')
        ->assertInertia(fn (Assert $page) => $page->where('rounds', [['year' => 2026, 'quarter' => 1], ['year' => 2026, 'quarter' => 2]]));
    expect($f->q1)->toBeInt();
});
