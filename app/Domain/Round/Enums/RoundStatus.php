<?php

declare(strict_types=1);

namespace App\Domain\Round\Enums;

use App\Support\Enums\HasLabel;

/**
 * v1 has no separate "published" state: closed = published (ARCHITECTURE.md D-07).
 */
enum RoundStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Closed = 'closed';

    protected static function labelGroup(): string
    {
        return 'round_status';
    }
}
