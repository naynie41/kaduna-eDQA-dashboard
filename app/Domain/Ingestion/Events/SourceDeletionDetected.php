<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The reconcile sweep found a submission we hold that its source no longer returns (ARCHITECTURE.md
 * §5.3, D-62). Only flagged (submissions.deleted_at_source): deleting a published assessment is an
 * administrator's decision, not a sync. For the Data sources page and the daily digest.
 */
final class SourceDeletionDetected
{
    use Dispatchable;

    public function __construct(
        public readonly int $submissionId,
        public readonly string $source,
        public readonly string $externalId,
    ) {}
}
