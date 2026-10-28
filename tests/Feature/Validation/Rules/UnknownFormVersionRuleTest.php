<?php

declare(strict_types=1);

use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\UnknownFormVersionRule;
use Tests\Support\ParsedSubmissionBuilder;

it('is the hard rule that runs before parsing matters', function (): void {
    $rule = app(UnknownFormVersionRule::class);

    expect($rule->code())->toBe('UNKNOWN_FORM_VERSION')
        ->and($rule->severity())->toBe(Severity::Hard)
        ->and($rule->dependsOnParse())->toBeFalse();
});

it('passes a form version with a field map', function (): void {
    expect(checkRule(UnknownFormVersionRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([]);
});

it('quarantines a form version with no field map', function (): void {
    $failures = checkRule(UnknownFormVersionRule::class, ParsedSubmissionBuilder::new()->withFormVersion('2021.3')->build());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->code)->toBe('UNKNOWN_FORM_VERSION')
        ->and($failures[0]->severity)->toBe(Severity::Hard)
        ->and($failures[0]->detail)->toBe('odk form version 2021.3 has no field map')
        ->and($failures[0]->field)->toBe('form_version');
});

it('fails when the parser says unknown, even if a map exists now', function (): void {
    $failures = checkRule(UnknownFormVersionRule::class, ParsedSubmissionBuilder::new()->withFormVersion('2026.1', known: false)->build());

    expect($failures[0]->detail ?? null)->toBe('odk form version 2026.1 has no field map');
});

it('fails when the parser says known but the version has no map in config', function (): void {
    $failures = checkRule(UnknownFormVersionRule::class, ParsedSubmissionBuilder::new()->withFormVersion('2021.3', known: true)->build());

    expect($failures)->toHaveCount(1);
});

it('names a blank form version as blank', function (): void {
    $failures = checkRule(UnknownFormVersionRule::class, ParsedSubmissionBuilder::new()->withFormVersion('  ')->build());

    expect($failures[0]->detail ?? null)->toBe('odk form version (blank) has no field map');
});

it('quarantines a version another source maps, and names the source it came from', function (): void {
    // 2026.1 has a map for ODK; on Kobo no version is mapped yet.
    $failures = checkRule(UnknownFormVersionRule::class, ParsedSubmissionBuilder::new()->fromSource('kobo')->withFormVersion('vQ8xK2', known: true)->build());
    $same = checkRule(UnknownFormVersionRule::class, ParsedSubmissionBuilder::new()->fromSource('kobo')->withFormVersion('2026.1', known: true)->build());

    expect($failures[0]->detail ?? null)->toBe('kobo form version vQ8xK2 has no field map')
        ->and($same[0]->detail ?? null)->toBe('kobo form version 2026.1 has no field map');
});
