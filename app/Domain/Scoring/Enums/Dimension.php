<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Enums;

use App\Support\Enums\HasLabel;

enum Dimension: string
{
    use HasLabel;

    case Availability = 'availability';
    case Consistency = 'consistency';
    case Validity = 'validity';

    protected static function labelGroup(): string
    {
        return 'dimension';
    }
}
