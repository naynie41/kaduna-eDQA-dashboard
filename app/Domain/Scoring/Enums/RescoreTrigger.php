<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Enums;

/** What asked for a rescore (ARCHITECTURE.md §7: publish, quarantine, correction, manual button). */
enum RescoreTrigger: string
{
    case Publish = 'publish';
    case Quarantine = 'quarantine';
    case Correction = 'correction';
    case Manual = 'manual';
    case Console = 'console';
}
