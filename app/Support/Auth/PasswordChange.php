<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives an administrator a new password (SECURITY.md §2), validated by the caller: a new
 * remember token, every stored session ended but the one kept (none after a reset), and the
 * change audited with how it was made. The User model records it in the password history.
 */
final class PasswordChange
{
    /** @param  'settings'|'reset'  $via */
    public static function apply(User $user, string $password, string $via, ?string $keepSession = null): void
    {
        DB::transaction(function () use ($user, $password, $keepSession): void {
            $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
            $user->save();
            UserSessions::end($user, except: $keepSession);
        });

        AuthAudit::record('password-changed', $user, ['via' => $via]);
    }
}
