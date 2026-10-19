<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** What a dry-run pull found: nothing in it was written anywhere. */
final readonly class DryRunReport
{
    /**
     * @param  array{accepted: int, flagged: int, quarantined: int}  $outcomes  of the submissions not yet stored
     * @param  array<string, array{severity: string, count: int}>  $rules  rule code => submissions it hit
     */
    public function __construct(
        public int $fetched,
        public int $alreadyStored,
        public array $outcomes,
        public array $rules,
    ) {}
}
