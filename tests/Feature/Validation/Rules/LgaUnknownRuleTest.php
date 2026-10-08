<?php

declare(strict_types=1);

use App\Domain\Facility\Models\Lga;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\LgaUnknownRule;
use Tests\Support\ParsedSubmissionBuilder;

it('is a hard rule that needs the parse', function (): void {
    $rule = app(LgaUnknownRule::class);

    expect($rule->code())->toBe('LGA_UNKNOWN')
        ->and($rule->severity())->toBe(Severity::Hard)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes an LGA given by code', function (): void {
    expect(checkRule(LgaUnknownRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([]);
});

it('passes an LGA given by name, ignoring case, spacing and hyphens', function (string $ref): void {
    Lga::factory()->create(['code' => 'BGW', 'name' => 'Birnin Gwari']);

    expect(checkRule(LgaUnknownRule::class, ParsedSubmissionBuilder::new()->withLga($ref)->build()))->toBe([]);
})->with(['Birnin Gwari', '  birnin-GWARI ', 'bgw']);

it('fails a blank LGA, which made a 24th LGA in the live report', function (?string $ref): void {
    $failures = checkRule(LgaUnknownRule::class, ParsedSubmissionBuilder::new()->withLga($ref)->build());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->severity)->toBe(Severity::Hard)
        ->and($failures[0]->detail)->toBe('lga was blank')
        ->and($failures[0]->field)->toBe('lga');
})->with([null, '', '   ', 'null', 'NULL']);

it('fails an LGA that is not one of the 23', function (): void {
    $failures = checkRule(LgaUnknownRule::class, ParsedSubmissionBuilder::new()->withLga('Kaduna Central')->build());

    expect($failures[0]->detail ?? null)->toBe("lga 'Kaduna Central' is not one of the 23");
});
