<?php

declare(strict_types=1);

use App\Domain\Facility\Models\Facility;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\FacilityUnknownRule;
use Tests\Support\ParsedSubmissionBuilder;

it('is a hard rule that needs the parse', function (): void {
    $rule = app(FacilityUnknownRule::class);

    expect($rule->code())->toBe('FACILITY_UNKNOWN')
        ->and($rule->severity())->toBe(Severity::Hard)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes an active facility on the master list', function (): void {
    expect(checkRule(FacilityUnknownRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([]);
});

it('passes a code with stray spaces around it', function (): void {
    $facility = Facility::factory()->create(['code' => 'KD/CHK/0042']);

    expect(checkRule(FacilityUnknownRule::class, ParsedSubmissionBuilder::new()->forFacility($facility)->withFacilityRef(' KD/CHK/0042 ')->build()))->toBe([]);
});

it('fails a code that is not on the master list', function (): void {
    $failures = checkRule(FacilityUnknownRule::class, ParsedSubmissionBuilder::new()->withFacilityRef('KD/XXX/9999')->build());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->code)->toBe('FACILITY_UNKNOWN')
        ->and($failures[0]->detail)->toBe("facility 'KD/XXX/9999' is not on the master list")
        ->and($failures[0]->field)->toBe('facility_code');
});

it('names a number where a code should be, like the live report\'s 2019.00', function (string $ref): void {
    $failures = checkRule(FacilityUnknownRule::class, ParsedSubmissionBuilder::new()->withFacilityRef($ref)->build());

    expect($failures[0]->detail ?? null)->toBe("facility ref '".trim($ref)."' looks like a year or number, not a facility code");
})->with(['2019.00', '2019', ' 42 ']);

it('fails a deactivated facility', function (): void {
    $facility = Facility::factory()->inactive()->create(['code' => 'KD/CHK/0043']);

    $failures = checkRule(FacilityUnknownRule::class, ParsedSubmissionBuilder::new()->forFacility($facility)->build());

    expect($failures[0]->detail ?? null)->toBe('facility KD/CHK/0043 is deactivated');
});

it('fails a blank facility', function (?string $ref): void {
    expect(checkRule(FacilityUnknownRule::class, ParsedSubmissionBuilder::new()->withFacilityRef($ref)->build())[0]->detail ?? null)
        ->toBe('facility was blank');
})->with([null, '', ' ', 'NULL']);

it('matches codes exactly: a different case is not the same facility', function (): void {
    $facility = Facility::factory()->create(['code' => 'KD/CHK/0042']);

    expect(checkRule(FacilityUnknownRule::class, ParsedSubmissionBuilder::new()->forFacility($facility)->withFacilityRef('kd/chk/0042')->build()))
        ->toHaveCount(1);
});
