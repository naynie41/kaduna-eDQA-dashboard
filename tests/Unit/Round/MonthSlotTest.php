<?php

declare(strict_types=1);

use App\Domain\Round\MonthSlot;

// ARCHITECTURE.md §3 "Month-slot mapping", the same table as resources/js/lib/months.test.ts.

it('maps each quarter and slot to its calendar month', function (int $quarter, int $slot, int $month): void {
    expect(MonthSlot::month($quarter, $slot))->toBe($month);
})->with([
    [1, 1, 1], [1, 2, 2], [1, 3, 3],
    [2, 1, 4], [2, 2, 5], [2, 3, 6],
    [3, 1, 7], [3, 2, 8], [3, 3, 9],
    [4, 1, 10], [4, 2, 11], [4, 3, 12],
]);

it('labels a slot with its month and year', function (): void {
    expect(MonthSlot::label(2026, 2, 1))->toBe('April 2026')
        ->and(MonthSlot::label(2025, 4, 3))->toBe('December 2025');
});

it('refuses a quarter or slot that does not exist', function (int $quarter, int $slot): void {
    expect(fn () => MonthSlot::month($quarter, $slot))->toThrow(InvalidArgumentException::class);
})->with([[0, 1], [5, 1], [2, 0], [2, 4]]);
