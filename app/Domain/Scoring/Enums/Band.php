<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Enums;

use App\Support\Enums\HasLabel;

/**
 * Score bands. Thresholds live in the scoring rule version, not here (ARCHITECTURE.md §7).
 */
enum Band: string
{
    use HasLabel;

    case Strong = 'strong';
    case Acceptable = 'acceptable';
    case Review = 'review';
    case NeedsAction = 'needs_action';

    protected static function labelGroup(): string
    {
        return 'band';
    }
}
