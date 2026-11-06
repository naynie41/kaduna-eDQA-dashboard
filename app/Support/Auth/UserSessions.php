<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends a user's stored sessions (the database session driver, SECURITY.md §2): after a password
 * change all but the current one, after a reset all of them.
 */
final class UserSessions
{
    public static function end(User $user, ?string $except = null): void
    {
        DB::table('sessions')->where('user_id', $user->id)
            ->when($except !== null, fn ($query) => $query->where('id', '!=', $except))
            ->delete();
    }
}
