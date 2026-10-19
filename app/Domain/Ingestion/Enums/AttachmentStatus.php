<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Enums;

/**
 * An ODK attachment's state (D-52, Q-18): pending until fetched; fetched once stored; rejected
 * when its name, type or size is refused (the file is deleted, never served); failed when ODK
 * could not deliver it after every retry (edqa:attachments:fetch-pending tries again).
 */
enum AttachmentStatus: string
{
    case Pending = 'pending';
    case Fetched = 'fetched';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
