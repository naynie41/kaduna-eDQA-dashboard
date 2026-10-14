<?php

declare(strict_types=1);

use App\Domain\Scoring\Presentation;

// CONVENTION.md §2.7: the one place a score is rounded for display. Q-09: half-up (default) or
// truncate; the live tool's private overall of 73.67 reads 73, which only truncation gives.

it('rounds half up by default', function (float $value, int $dp, float $expected): void {
    expect(config('edqa.presentation.rounding'))->toBe('half_up')
        ->and(Presentation::round($value, $dp))->toBe($expected);
})->with([
    [73.67, 0, 74.0],
    [89.95, 1, 90.0],
    [89.94, 1, 89.9],
    [69.95, 1, 70.0],
    [0.05, 1, 0.1],
    [100.0, 1, 100.0],
    [87.666666666, 2, 87.67],
]);

it('truncates when configured to', function (float $value, int $dp, float $expected): void {
    config(['edqa.presentation.rounding' => 'truncate']);

    expect(Presentation::round($value, $dp))->toBe($expected);
})->with([
    [73.67, 0, 73.0],
    [89.99, 1, 89.9],
    [90.0, 1, 90.0],
    [0.29, 2, 0.29],       // 0.29 × 100 is 28.999… in floating point: must not become 0.28
    [79.95, 1, 79.9],
]);

it('gives the headline figure as a whole number in the configured mode', function (): void {
    expect(Presentation::headline(73.67))->toBe(74)
        ->and(Presentation::headline(73.49))->toBe(73);

    config(['edqa.presentation.rounding' => 'truncate']);

    expect(Presentation::headline(73.67))->toBe(73)
        ->and(Presentation::headline(100.0))->toBe(100);
});

it('refuses a rounding mode it does not know', function (): void {
    config(['edqa.presentation.rounding' => 'banker']);

    expect(fn () => Presentation::round(1.25, 1))->toThrow(InvalidArgumentException::class);
});
