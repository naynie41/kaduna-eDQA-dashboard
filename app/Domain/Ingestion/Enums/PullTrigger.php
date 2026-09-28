<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Enums;

use App\Support\Enums\HasLabel;

/**
 * What started an ODK pull: the 10-minute schedule, an admin's "Pull now", or the webhook.
 */
enum PullTrigger: string
{
    use HasLabel;

    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Webhook = 'webhook';

    protected static function labelGroup(): string
    {
        return 'pull_trigger';
    }
}
