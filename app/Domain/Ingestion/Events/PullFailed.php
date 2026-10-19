<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The pull failed after every retry (PullSubmissionsJob::failed). For the admin alert and the daily
 * digest (ARCHITECTURE.md §5.3). The message is an administrator's: never a secret or a payload.
 */
final class PullFailed
{
    use Dispatchable;

    public function __construct(
        public readonly ?int $runId,
        public readonly string $message,
    ) {}
}
