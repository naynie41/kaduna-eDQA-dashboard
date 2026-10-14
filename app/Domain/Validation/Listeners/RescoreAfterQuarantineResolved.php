<?php

declare(strict_types=1);

namespace App\Domain\Validation\Listeners;

use App\Domain\Validation\Events\QuarantineResolved;
use Illuminate\Support\Facades\Log;

/**
 * PLACEHOLDER: resolving a quarantined record triggers a rescore of its round (ARCHITECTURE.md
 * §6). Phase 3 replaces the log line with RescoreRoundJob; until then the request is recorded so
 * none is lost silently.
 */
final class RescoreAfterQuarantineResolved
{
    public function handle(QuarantineResolved $event): void
    {
        Log::channel('edqa')->info('quarantine.resolved.rescore_pending', [
            'quarantined_record_id' => $event->quarantinedRecordId,
            'round_id' => $event->roundId,
            'resolution' => $event->resolution->value,
        ]);
    }
}
