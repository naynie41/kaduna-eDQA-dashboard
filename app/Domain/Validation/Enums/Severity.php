<?php

declare(strict_types=1);

namespace App\Domain\Validation\Enums;

use App\Support\Enums\HasLabel;

/**
 * Hard failures quarantine a submission; soft failures accept it with a flag.
 */
enum Severity: string
{
    use HasLabel;

    case Hard = 'hard';
    case Soft = 'soft';

    protected static function labelGroup(): string
    {
        return 'severity';
    }
}
