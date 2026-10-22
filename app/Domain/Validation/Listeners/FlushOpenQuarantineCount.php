<?php

declare(strict_types=1);

namespace App\Domain\Validation\Listeners;

use App\Domain\Validation\Queries\QuarantineQuery;

/**
 * The open-quarantine count every page shows (the shell, the Data issues badge) is cached; a
 * record quarantined or resolved forgets it. Both events dispatch after commit, so the next read
 * counts the committed rows.
 */
final class FlushOpenQuarantineCount
{
    public function handle(object $event): void
    {
        QuarantineQuery::forgetOpenCount();
    }
}
