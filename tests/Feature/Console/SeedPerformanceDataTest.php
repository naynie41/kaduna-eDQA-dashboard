<?php

declare(strict_types=1);

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\ScoreCalculator;
use Database\Seeders\LgaSeeder;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

// edqa:seed:perf (ARCHITECTURE.md §10): a small run here; the full ~44,000 in ServerTimingTest.

beforeEach(function (): void {
    $this->seed([LgaSeeder::class, ScoringRuleVersionSeeder::class]);
    CurrentRuleVersion::forget();
    $lga = (int) DB::table('lgas')->value('id');
    $ward = Rows::ward(['lga_id' => $lga]);
    foreach (range(1, 6) as $i) {
        Rows::facility(['code' => "PERF-T{$i}", 'lga_id' => $lga, 'ward_id' => $ward, 'ownership' => $i % 3 === 0 ? 'private' : 'public']);
    }
    Rows::facility(['code' => 'PERF-GONE', 'lga_id' => $lga, 'ward_id' => $ward, 'is_active' => false]);
});

it('fills every quarter of the years asked with one scored assessment per active facility', function (): void {
    $this->artisan('edqa:seed:perf', ['--years' => 1, '--items-per-slot' => 4])->assertSuccessful();

    $rounds = DB::table('rounds')->orderBy('year')->orderBy('quarter')->get();
    expect($rounds)->toHaveCount(4)
        ->and($rounds->pluck('status')->all())->toBe(['closed', 'closed', 'closed', 'open'])
        ->and($rounds->whereNotNull('rule_version_id'))->toHaveCount(3)
        ->and(DB::table('assessments')->count())->toBe(24) // 6 active facilities × 4 rounds
        ->and(DB::table('assessments')->where('status', 'accepted')->count())->toBe(24)
        ->and(DB::table('item_responses')->count())->toBe(24 * 9 * 4)
        ->and(DB::table('assessment_scores')->count())->toBe(24 * 9)
        ->and(DB::table('round_aggregates')->count())->toBeGreaterThan(0);
});

it('derives every score from its items exactly as ScoreCalculator does', function (): void {
    $this->artisan('edqa:seed:perf', ['--years' => 1, '--items-per-slot' => 6])->assertSuccessful();
    $rules = app(CurrentRuleVersion::class)->config();
    $calculator = app(ScoreCalculator::class);

    foreach (DB::table('assessments')->pluck('id') as $assessment) {
        $items = DB::table('item_responses')->where('assessment_id', $assessment)->get()->map(fn (object $row): ItemResponseData => new ItemResponseData(
            Dimension::from((string) $row->dimension), (int) $row->month_slot, (string) $row->item_code, $rules->outcome((string) $row->value), (bool) $row->is_applicable,
        ))->all();
        $card = $calculator->calculate($items, $rules);

        foreach (DB::table('assessment_scores')->where('assessment_id', $assessment)->get() as $stored) {
            // Stored as numeric(5,2), as every score is.
            expect((float) $stored->score)->toEqualWithDelta(round((float) $card->slots[$stored->dimension][$stored->month_slot], 2), 0.0001);
        }
    }
});

it('keeps what is there and adds only the missing pairs, so it can run twice', function (): void {
    $this->artisan('edqa:seed:perf', ['--years' => 1, '--items-per-slot' => 2])->assertSuccessful();
    $this->artisan('edqa:seed:perf', ['--years' => 1, '--items-per-slot' => 2])->assertSuccessful();

    expect(DB::table('assessments')->count())->toBe(24)
        ->and(DB::table('rounds')->count())->toBe(4);
});

it('refuses outside local and testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('edqa:seed:perf')->assertFailed();
    expect(DB::table('assessments')->count())->toBe(0);
});

it('refuses a weighted rule version, which only the real rescore can score', function (): void {
    $config = json_decode((string) DB::table('scoring_rule_versions')->value('config'), true);
    $config['item_weights'] = ['availability.item_1' => 2.0];
    DB::table('scoring_rule_versions')->update(['config' => json_encode($config)]);
    CurrentRuleVersion::forget();

    $this->artisan('edqa:seed:perf', ['--years' => 1])->assertFailed();
    expect(DB::table('assessments')->count())->toBe(0);
});
