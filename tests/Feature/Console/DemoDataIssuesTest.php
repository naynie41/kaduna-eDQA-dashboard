<?php

declare(strict_types=1);

use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use App\Domain\Ingestion\Actions\SendDemoDataIssues;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Actions\CorrectAndReprocess;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// edqa:demo:data-issues (D-57): known broken submissions sent through the real pipeline for the
// Data issues page's Playwright run and the client demo. Local and testing only.

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $lga = Lga::factory()->create(['code' => 'CHK', 'name' => 'Chikun']);
    $ward = Ward::factory()->create(['lga_id' => $lga->id]);
    Facility::factory()->inWard($ward)->count(4)->create();
    $this->round = Round::factory()->create(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);
});

/** @return list<string> */
function demoIssueCodes(string $instanceId): array
{
    return array_column(QuarantinedRecord::query()->where('instance_id', $instanceId)->sole()->failures, 'code');
}

it('holds a null-LGA record and two records failing only ROUND_WINDOW, and prints them as JSON', function (): void {
    $this->artisan('edqa:demo:data-issues')->assertSuccessful();

    expect(demoIssueCodes(SendDemoDataIssues::PREFIX.'a-null-lga'))->toBe(['LGA_UNKNOWN'])
        ->and(demoIssueCodes(SendDemoDataIssues::PREFIX.'a-quarter-1'))->toBe(['ROUND_WINDOW'])
        ->and(demoIssueCodes(SendDemoDataIssues::PREFIX.'a-quarter-2'))->toBe(['ROUND_WINDOW'])
        ->and(DB::table('assessments')->count())->toBe(0);

    $printed = app(SendDemoDataIssues::class)->execute();
    $facility = Facility::query()->where('code', $printed['null_lga']['facility_code'])->sole();
    expect($printed['round_id'])->toBe($this->round->id)
        ->and($printed['null_lga']['lga_code'])->toBe('CHK')
        ->and($printed['null_lga']['facility_id'])->toBe($facility->id)
        ->and($printed['wrong_quarter'])->toHaveCount(2);
});

it('is correctable: the null-LGA record is accepted once its LGA is given', function (): void {
    app(SendDemoDataIssues::class)->execute();
    $record = QuarantinedRecord::query()->where('instance_id', SendDemoDataIssues::PREFIX.'a-null-lga')->sole();

    $outcome = app(CorrectAndReprocess::class)->execute($record, ['lga_ref' => 'CHK'], User::factory()->create(), 'Assessor confirmed the LGA');

    expect($outcome->result->hardFailures)->toBe([])->and($outcome->status->value)->toBe('accepted');
});

it('runs once: a second run changes nothing and prints the same records', function (): void {
    $first = app(SendDemoDataIssues::class)->execute();
    $second = app(SendDemoDataIssues::class)->execute();

    expect($second)->toBe($first)
        ->and(DB::table('submissions')->count())->toBe(3);
});

it('refuses outside local and testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('edqa:demo:data-issues')->assertFailed();
    expect(DB::table('submissions')->count())->toBe(0);
});

it('refuses when there is no open round', function (): void {
    $this->round->delete();

    $this->artisan('edqa:demo:data-issues')->assertFailed();
    expect(DB::table('submissions')->count())->toBe(0);
});

it('sends a new batch to facilities the earlier batch did not use, and refuses a bad tag', function (): void {
    Facility::factory()->inWard(Ward::query()->firstOrFail())->count(2)->create();
    $first = app(SendDemoDataIssues::class)->execute('a');
    $second = app(SendDemoDataIssues::class)->execute('b');

    $codes = fn (array $held): array => [$held['null_lga']['facility_code'], ...array_column($held['wrong_quarter'], 'facility_code')];
    expect(array_intersect($codes($first), $codes($second)))->toBe([])
        ->and(DB::table('submissions')->count())->toBe(6);

    $this->artisan('edqa:demo:data-issues --batch="Not ok"')->assertFailed();
});
