<?php

declare(strict_types=1);

namespace App\Domain\Validation\Events;

use App\Domain\Validation\Enums\QuarantineResolution;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A quarantined record was resolved: reprocessed, rejected or superseded (ARCHITECTURE.md §2:
 * the round is rescored). Dispatched after the transaction commits.
 */
final readonly class QuarantineResolved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $quarantinedRecordId,
        public ?int $roundId,
        public QuarantineResolution $resolution,
    ) {}
}
