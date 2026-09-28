<?php

declare(strict_types=1);

namespace App\Domain\Validation\Enums;

use App\Support\Enums\HasLabel;

/**
 * How a quarantined record was resolved (ARCHITECTURE.md §6, Quarantine workflow).
 */
enum QuarantineResolution: string
{
    use HasLabel;

    /** Corrected through an overlay and re-run through the pipeline. */
    case Reprocessed = 'reprocessed';

    /** Excluded permanently, with a typed reason. */
    case Rejected = 'rejected';

    /** Fixed in ODK Central: an edit arrived with this record's instance ID as deprecatedID. */
    case Superseded = 'superseded';

    protected static function labelGroup(): string
    {
        return 'quarantine_resolution';
    }
}
