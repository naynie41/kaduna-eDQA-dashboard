<?php

declare(strict_types=1);

use App\Support\Text\Normalise;

it('treats blank, whitespace and the word null as no value', function (?string $value): void {
    expect(Normalise::nullIfBlank($value))->toBeNull();
})->with([null, '', '   ', "\t\n", 'null', 'NULL', ' Null ']);

it('trims a real value and keeps its case', function (): void {
    expect(Normalise::nullIfBlank('  KD/CHK/0042 '))->toBe('KD/CHK/0042')
        ->and(Normalise::nullIfBlank('nullah'))->toBe('nullah');
});

it('collapses inner whitespace', function (): void {
    expect(Normalise::collapse("  Hauwa \t  Ibrahim  "))->toBe('Hauwa Ibrahim');
});

it('folds a name for comparison: case and spacing ignored', function (): void {
    expect(Normalise::name('  HAUWA   ibrahim '))->toBe(Normalise::name('Hauwa Ibrahim'))
        ->and(Normalise::name('Hauwa Ibrahim'))->toBe('hauwa ibrahim');
});

it('makes a match key that also ignores punctuation', function (?string $value, ?string $key): void {
    expect(Normalise::matchKey($value))->toBe($key);
})->with([
    ['Birnin Gwari', 'birnin gwari'],
    ['  birnin-GWARI ', 'birnin gwari'],
    ["Jema'a", 'jema a'],
    ['BGW', 'bgw'],
    ['---', null],
    [null, null],
    ['null', null],
]);

it('recognises a purely numeric value', function (string $value, bool $numeric): void {
    expect(Normalise::isNumeric($value))->toBe($numeric);
})->with([
    ['2019.00', true], ['12', true], [' 42 ', true], ['-3', true], ['1,5', true],
    ['KD/2019', false], ['Kawo 2', false], ['', false], ['2019.00.1', false],
]);

it('accepts ISO 8601 dates and date-times, and nothing else', function (string $value, bool $iso): void {
    expect(Normalise::isIsoDate($value))->toBe($iso);
})->with([
    'ODK timestamp' => ['2026-04-14T09:00:00.000+01:00', true],
    'UTC' => ['2026-04-14T08:00:00Z', true],
    'no seconds' => ['2026-04-14T09:00+01:00', true],
    'date only' => ['2026-04-14', true],
    'month name' => ['FEBRUARY', false],
    'impossible day' => ['2026-02-30T09:00:00.000+01:00', false],
    'impossible hour' => ['2026-04-14T25:00:00+01:00', false],
    'day first' => ['14/04/2026', false],
    'blank' => ['', false],
    'null' => ['null', false],
]);
