<?php

declare(strict_types=1);

use App\Domain\Round\Enums\RoundStatus;
use Tests\Support\Rows;

it('accepts every RoundStatus the app defines', function (RoundStatus $status): void {
    expect(Rows::round(['status' => $status->value]))->toBeInt();
})->with(fn (): array => RoundStatus::cases());

it('accepts quarters 1 to 4', function (int $quarter): void {
    expect(Rows::round(['quarter' => $quarter]))->toBeInt();
})->with([1, 2, 3, 4]);

it('rejects a quarter outside 1 to 4', function (int $quarter): void {
    expectCheckViolation(fn () => Rows::round(['quarter' => $quarter]), 'quarter_valid');
})->with([0, 5, -1]);

it('rejects a window that ends before it starts', function (): void {
    expectCheckViolation(
        fn () => Rows::round(['window_start' => '2026-04-01', 'window_end' => '2026-03-31']),
        'window_ordered',
    );
});

it('accepts a one-day window', function (): void {
    expect(Rows::round(['window_start' => '2026-04-01', 'window_end' => '2026-04-01']))->toBeInt();
});

it('rejects a status other than open or closed', function (): void {
    // "Published" is not a separate state in v1 (ARCHITECTURE.md D-07).
    expectCheckViolation(fn () => Rows::round(['status' => 'published']), 'status_valid');
});

it('rejects a second round for the same year and quarter', function (): void {
    Rows::round(['year' => 2026, 'quarter' => 2]);

    expectUniqueViolation(fn () => Rows::round(['year' => 2026, 'quarter' => 2]), 'rounds_year_quarter_unique');
});
