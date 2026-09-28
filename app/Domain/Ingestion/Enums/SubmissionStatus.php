<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Enums;

use App\Support\Enums\HasLabel;

/**
 * Submission lifecycle (ARCHITECTURE.md §3):
 * received → parsed → validated → accepted | flagged | quarantined → (reprocessed) | rejected;
 * any state → superseded when an ODK edit replaces it.
 */
enum SubmissionStatus: string
{
    use HasLabel;

    case Received = 'received';
    case Parsed = 'parsed';
    case Validated = 'validated';
    case Accepted = 'accepted';
    case Flagged = 'flagged';
    case Quarantined = 'quarantined';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    protected static function labelGroup(): string
    {
        return 'submission_status';
    }
}
