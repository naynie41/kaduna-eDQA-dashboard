<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Enums;

use App\Support\Enums\HasLabel;

/**
 * What started a pull from a source: the 10-minute schedule, an admin's "Pull now", the webhook,
 * the historical backfill (edqa:backfill), or the reconcile sweep (edqa:reconcile, nightly).
 */
enum PullTrigger: string
{
    use HasLabel;

    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Webhook = 'webhook';
    case Backfill = 'backfill';
    case Reconcile = 'reconcile';

    protected static function labelGroup(): string
    {
        return 'pull_trigger';
    }
}
