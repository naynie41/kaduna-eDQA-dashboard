<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Reporting\Aggregators\ShellStatusAggregator;
use App\Domain\Validation\Queries\QuarantineQuery;

/**
 * Each open round, newest first, with its accepted and flagged assessments (Reporting) and open
 * quarantine records (Validation), across every source: GET /api/sources.
 */
final class OpenRoundsPayload
{
    public function __construct(
        private readonly ShellStatusAggregator $shell,
        private readonly QuarantineQuery $quarantine,
    ) {}

    /** @return list<array{id: int, year: int, quarter: int, accepted: int, flagged: int, quarantined: int}> */
    public function build(): array
    {
        $rounds = $this->shell->openRounds();
        $quarantined = $this->quarantine->openCountsByRound(array_map(fn (array $round): int => $round['id'], $rounds));

        return array_map(fn (array $round): array => [...$round, 'quarantined' => $quarantined[$round['id']] ?? 0], $rounds);
    }
}
