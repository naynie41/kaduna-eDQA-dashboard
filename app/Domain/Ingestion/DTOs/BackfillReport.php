<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** The backfill report's figures (BackfillReportQuery). */
final readonly class BackfillReport
{
    /**
     * @param  list<array{source: ?string, total: bool, round_id: ?int, year: ?int, quarter: ?int, pulled: int, accepted: int, flagged: int, quarantined: int, rejected: int, unprocessed: int}>  $rounds  source null: the grand total
     * @param  list<array{source: string, code: string, year: int, records: int}>  $byYear
     * @param  list<array{source: string, code: string, version: string, records: int}>  $byVersion
     * @param  list<array{code: string, detail: string, records: int}>  $examples
     * @param  list<array{source: string, duplicate_assessment: int, against_other_source: int, cross_source_duplicates: int}>  $duplicates
     */
    public function __construct(
        public int $submissions,
        public int $superseded,
        public array $rounds,
        public array $byYear,
        public array $byVersion,
        public array $examples,
        public array $duplicates = [],
    ) {}
}
