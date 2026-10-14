<?php

declare(strict_types=1);

namespace App\Domain\Validation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A submission failed a hard rule and was quarantined (ARCHITECTURE.md §2: a listener will bump
 * the Data issues badge). Dispatched after the transaction commits.
 */
final readonly class SubmissionQuarantined implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $quarantinedRecordId,
        public int $submissionId,
    ) {}
}
