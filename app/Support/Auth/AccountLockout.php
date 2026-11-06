<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;

/**
 * The progressive lockout per account (SECURITY.md §2, config edqa.auth.lockout): after 5 failed
 * sign-ins locked for 15 minutes, after 10 for an hour, after 20 until `edqa:admin:unlock`. The
 * count runs on across lockouts and is reset only by a successful sign-in or an unlock.
 */
final class AccountLockout
{
    public function isLocked(User $user): bool
    {
        return $user->locked_out_at !== null || ($user->locked_until !== null && $user->locked_until->isFuture());
    }

    /** A wrong password on an unlocked account: counted, and a lockout when a step is reached. */
    public function recordFailure(User $user): void
    {
        $user->increment('failed_login_count');
        $failures = $user->failed_login_count;

        foreach ((array) config('edqa.auth.lockout') as $step) {
            if (! is_array($step) || $step['failures'] !== $failures) {
                continue;
            }

            $minutes = $step['minutes'];
            $user->forceFill(is_int($minutes)
                ? ['locked_until' => now()->addMinutes($minutes)]
                : ['locked_until' => null, 'locked_out_at' => now()])->save();

            AuthAudit::record('lockout', $user, [
                'failures' => $failures,
                'minutes' => is_int($minutes) ? $minutes : null,
                'until' => $user->locked_until?->toIso8601String(),
            ]);
        }
    }

    /** A successful sign-in starts the count again. */
    public function clear(User $user): void
    {
        if ($user->failed_login_count !== 0 || $user->locked_until !== null) {
            $user->forceFill(['failed_login_count' => 0, 'locked_until' => null])->save();
        }
    }

    /** `edqa:admin:unlock`: lifts any lockout and starts the count again. Audited as from the console. */
    public function unlock(User $user): void
    {
        $was = ['failures' => $user->failed_login_count, 'locked_until' => $user->locked_until?->toIso8601String(), 'locked_out_at' => $user->locked_out_at?->toIso8601String()];
        $user->forceFill(['failed_login_count' => 0, 'locked_until' => null, 'locked_out_at' => null])->save();

        AuthAudit::record('unlock', $user, ['old' => $was], byUser: false, context: ['source' => 'console']);
    }
}
