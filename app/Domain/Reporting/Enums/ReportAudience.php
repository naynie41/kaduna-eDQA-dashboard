<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who a report is for (SECURITY.md §3, D-77): Public sees closed rounds only (PublicScope); Admin,
 * a signed-in active administrator, sees every round (the admin preview). Password only, D-84.
 * Anything not known to be an administrator is Public.
 */
enum ReportAudience: string
{
    case Public = 'public';
    case Admin = 'admin';

    public static function of(?Authenticatable $user): self
    {
        return $user instanceof User && $user->is_active ? self::Admin : self::Public;
    }
}
