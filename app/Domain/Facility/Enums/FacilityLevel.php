<?php

declare(strict_types=1);

namespace App\Domain\Facility\Enums;

use App\Support\Enums\HasLabel;

/**
 * Mirrored by the facilities_level_valid CHECK constraint.
 */
enum FacilityLevel: string
{
    use HasLabel;

    case Primary = 'primary';
    case Secondary = 'secondary';
    case Tertiary = 'tertiary';

    protected static function labelGroup(): string
    {
        return 'facility_level';
    }
}
