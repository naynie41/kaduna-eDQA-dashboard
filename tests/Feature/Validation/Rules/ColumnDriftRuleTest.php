<?php

declare(strict_types=1);

use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\ColumnDriftRule;
use Tests\Support\ParsedSubmissionBuilder;

it('is a hard rule that needs the parse', function (): void {
    $rule = app(ColumnDriftRule::class);

    expect($rule->code())->toBe('COLUMN_DRIFT')
        ->and($rule->severity())->toBe(Severity::Hard)
        ->and($rule->dependsOnParse())->toBeTrue();
});

it('passes real dates and a real ward', function (): void {
    expect(checkRule(ColumnDriftRule::class, ParsedSubmissionBuilder::new()->build()))->toBe([]);
});

it("lists every shifted field in one failure, as the live report's row would need", function (): void {
    $submission = ParsedSubmissionBuilder::new()->withWard('Quarterly')->withRawStart('FEBRUARY')->build();

    $failures = checkRule(ColumnDriftRule::class, $submission);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->code)->toBe('COLUMN_DRIFT')
        ->and($failures[0]->detail)->toBe("ward 'Quarterly', start 'FEBRUARY'")
        ->and($failures[0]->field)->toBe('ward,start');
});

it('fails a ward that is a reserved word, ignoring case and spaces', function (string $ward): void {
    $failures = checkRule(ColumnDriftRule::class, ParsedSubmissionBuilder::new()->withWard($ward)->build());

    expect($failures[0]->detail ?? null)->toBe("ward '{$ward}'");
})->with(['Quarterly', ' quarterly ', 'MARCH', 'n/a', 'None', 'annual']);

it('fails a ward that is purely a number', function (string $ward): void {
    expect(checkRule(ColumnDriftRule::class, ParsedSubmissionBuilder::new()->withWard($ward)->build()))->toHaveCount(1);
})->with(['12', '2019.00']);

it('passes a ward that only contains a reserved word or a number', function (string $ward): void {
    expect(checkRule(ColumnDriftRule::class, ParsedSubmissionBuilder::new()->withWard($ward)->build()))->toBe([]);
})->with(['Marchland', 'Kawo 2', 'Unguwan Sarki']);

it('leaves a missing ward to other checks', function (): void {
    expect(checkRule(ColumnDriftRule::class, ParsedSubmissionBuilder::new()->withWard(null)->build()))->toBe([]);
});

it('fails a start or end that is not an ISO date, naming each', function (string $start, string $end, string $detail): void {
    $submission = ParsedSubmissionBuilder::new()->withRawStart($start)->withRawEnd($end)->build();

    expect(checkRule(ColumnDriftRule::class, $submission)[0]->detail ?? null)->toBe($detail);
})->with([
    'month name' => ['FEBRUARY', '2026-04-14T09:45:00.000+01:00', "start 'FEBRUARY'"],
    'impossible date' => ['2026-02-30T09:00:00.000+01:00', '2026-04-14T09:45:00.000+01:00', "start '2026-02-30T09:00:00.000+01:00'"],
    'end blank' => ['2026-04-14T09:00:00.000+01:00', '', "end ''"],
    'both' => ['null', '14/04/2026', "start 'null', end '14/04/2026'"],
]);

it('accepts any ISO 8601 form ODK may send', function (string $start): void {
    expect(checkRule(ColumnDriftRule::class, ParsedSubmissionBuilder::new()->withRawStart($start)->build()))->toBe([]);
})->with(['2026-04-14T08:00:00Z', '2026-04-14T09:00+01:00', '2026-04-14']);

it('reads the reserved words from config', function (): void {
    config(['edqa.validation.reserved_ward_words' => ['weekly']]);

    expect(checkRule(ColumnDriftRule::class, ParsedSubmissionBuilder::new()->withWard('Weekly')->build()))->toHaveCount(1)
        ->and(checkRule(ColumnDriftRule::class, ParsedSubmissionBuilder::new()->withWard('Quarterly')->build()))->toBe([]);
});
