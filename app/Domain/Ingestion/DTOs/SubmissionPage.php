<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** One page from a SubmissionSource: the raw entries, untrusted, as the source sent them. */
final readonly class SubmissionPage
{
    /** @param  list<array<array-key, mixed>>  $rows */
    public function __construct(
        public array $rows,
        public int $totalCount,
        public bool $hasMore,
    ) {}
}
