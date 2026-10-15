<?php

declare(strict_types=1);

namespace App\Domain\Round\DTOs;

use App\Domain\Round\Models\Round;

/** A created or re-windowed round, with any warning the window raised (allowed, but worth seeing). */
final readonly class RoundWindowResult
{
    /** @param  list<array{code: string, message: string}>  $warnings */
    public function __construct(
        public Round $round,
        public array $warnings,
    ) {}
}
