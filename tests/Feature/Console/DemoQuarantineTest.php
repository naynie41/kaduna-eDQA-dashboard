<?php

declare(strict_types=1);

use Database\Seeders\LgaSeeder;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

// edqa:demo:quarantine (docs/demo-script.md): the old report's broken row, sent through the real
// validation pipeline on local demo data, is held in quarantine and reaches no figure.

beforeEach(function (): void {
    $this->seed([LgaSeeder::class, ScoringRuleVersionSeeder::class]);
    Rows::round(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);
});

it('holds the live report row with every reason, and nothing reaches an assessment', function (): void {
    $lgas = DB::table('lgas')->count();

    $this->artisan('edqa:demo:quarantine')->assertSuccessful();

    $record = DB::table('quarantined_records')->sole();
    $codes = array_column(json_decode((string) $record->failures, true), 'code');
    expect($codes)->toEqualCanonicalizing(['COLUMN_DRIFT', 'LGA_UNKNOWN', 'FACILITY_UNKNOWN', 'SCORE_RANGE'])
        ->and(DB::table('submissions')->where('instance_id', 'uuid:demo-live-report-row')->value('status'))->toBe('quarantined')
        ->and(DB::table('assessments')->count())->toBe(0)
        ->and(DB::table('lgas')->count())->toBe($lgas); // still 23: nothing was inserted
});

it('runs once: a second run says it is already held', function (): void {
    $this->artisan('edqa:demo:quarantine')->assertSuccessful();
    $this->artisan('edqa:demo:quarantine')->assertSuccessful();

    expect(DB::table('quarantined_records')->count())->toBe(1)
        ->and(DB::table('submissions')->count())->toBe(1);
});

it('refuses outside local and testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('edqa:demo:quarantine')->assertFailed();
    expect(DB::table('submissions')->count())->toBe(0);
});
