<?php

declare(strict_types=1);

namespace App\Domain\Validation\Enums;

use App\Support\Enums\HasLabel;

enum QuarantineStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Resolved = 'resolved';

    protected static function labelGroup(): string
    {
        return 'quarantine_status';
    }
}
