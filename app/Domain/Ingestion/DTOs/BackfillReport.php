<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** The backfill report's figures (BackfillReportQuery). */
final readonly class BackfillReport
{
    /**
     * @param  list<array{total: bool, round_id: ?int, year: ?int, quarter: ?int, pulled: int, accepted: int, flagged: int, quarantined: int, rejected: int, unprocessed: int}>  $rounds
     * @param  list<array{code: string, year: int, records: int}>  $byYear
     * @param  list<array{code: string, version: string, records: int}>  $byVersion
     * @param  list<array{code: string, detail: string, records: int}>  $examples
     */
    public function __construct(
        public int $submissions,
        public int $superseded,
        public array $rounds,
        public array $byYear,
        public array $byVersion,
        public array $examples,
    ) {}
}
