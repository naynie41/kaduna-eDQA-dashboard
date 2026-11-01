<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** An administrator reviewed a deletion at source (D-62): it no longer counts as an open issue. */
final class DeletionReviewed
{
    use Dispatchable;

    public function __construct(
        public readonly int $submissionId,
        public readonly string $source,
    ) {}
}
