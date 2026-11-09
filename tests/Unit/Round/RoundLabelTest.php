<?php

declare(strict_types=1);

use App\Domain\Round\Models\Round;
use App\Domain\Round\Support\RoundLabel;

// One way to name a round ("Q2 2026"): ratings, plans, refusals, rule failures and reports.

it('names a round by quarter and year', function (): void {
    expect(RoundLabel::of(2026, 2))->toBe('Q2 2026')
        ->and(RoundLabel::of(2025, 4))->toBe('Q4 2025');
});

it('gives a round model the same name', function (): void {
    expect((new Round(['year' => 2026, 'quarter' => 3]))->label())->toBe('Q3 2026');
});
