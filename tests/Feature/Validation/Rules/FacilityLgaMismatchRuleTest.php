<?php

declare(strict_types=1);

use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\FacilityLgaMismatchRule;
use Tests\Support\ParsedSubmissionBuilder;

function facilityInLga(string $code, string $name): Facility
{
    $lga = Lga::factory()->create(['code' => $code, 'name' => $name]);

    return Facility::factory()->inWard(Ward::factory()->create(['lga_id' => $lga->id]))->create(['code' => "KD/{$code}/0001"]);
}

it('is a hard rule that needs the parse', function (): void {
    $rule = app(FacilityLgaMismatchRule::class);

    expect($rule->code())->toBe('FACILITY_LGA_MISMATCH')
        ->and($rule->severity())->toBe(Severity::Hard)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes when the facility is in the LGA submitted', function (): void {
    expect(checkRule(FacilityLgaMismatchRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([]);
});

it('passes when the LGA is given by name and matches', function (): void {
    $facility = facilityInLga('CHK', 'Chikun');

    expect(checkRule(FacilityLgaMismatchRule::class, ParsedSubmissionBuilder::new()->forFacility($facility)->withLga('chikun')->build()))->toBe([]);
});

it('fails when the master list puts the facility in another LGA, naming both', function (): void {
    $facility = facilityInLga('CHK', 'Chikun');
    Lga::factory()->create(['code' => 'GWA', 'name' => 'Giwa']);

    $failures = checkRule(FacilityLgaMismatchRule::class, ParsedSubmissionBuilder::new()->forFacility($facility)->withLga('GWA')->build());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->code)->toBe('FACILITY_LGA_MISMATCH')
        ->and($failures[0]->detail)->toBe("facility 'KD/CHK/0001' is in Chikun on the master list, but the submission says Giwa")
        ->and($failures[0]->field)->toBe('lga');
});

it('stays silent when the LGA did not resolve: LGA_UNKNOWN reports that', function (?string $lga): void {
    expect(checkRule(FacilityLgaMismatchRule::class, ParsedSubmissionBuilder::new()->withLga($lga)->build()))->toBe([]);
})->with([null, 'Kaduna Central']);

it('stays silent when the facility did not resolve: FACILITY_UNKNOWN reports that', function (): void {
    expect(checkRule(FacilityLgaMismatchRule::class, ParsedSubmissionBuilder::new()->withFacilityRef('2019.00')->build()))->toBe([]);
});

it('still compares a deactivated facility, which is on the master list', function (): void {
    $facility = facilityInLga('CHK', 'Chikun');
    $facility->update(['is_active' => false]);
    Lga::factory()->create(['code' => 'GWA', 'name' => 'Giwa']);

    expect(checkRule(FacilityLgaMismatchRule::class, ParsedSubmissionBuilder::new()->forFacility($facility)->withLga('GWA')->build()))->toHaveCount(1);
});
