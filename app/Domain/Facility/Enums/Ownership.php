<?php

declare(strict_types=1);

namespace App\Domain\Facility\Enums;

use App\Support\Enums\HasLabel;

/**
 * Mirrored by the facilities_ownership_valid CHECK constraint.
 */
enum Ownership: string
{
    use HasLabel;

    case Public = 'public';
    case Private = 'private';

    protected static function labelGroup(): string
    {
        return 'ownership';
    }
}
