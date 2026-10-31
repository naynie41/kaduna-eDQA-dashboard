<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * EDQA_REQUIRE_2FA (SECURITY.md §2): two-factor sign-in is required everywhere. Only local
 * development may switch it off.
 *
 * - assertAllowed(), at boot: false in any environment but local stops the app with a clear error,
 *   as the *_ALLOW_INSECURE_LOCAL flags are refused outside local.
 * - required(), per request: off only when the flag is false **and** the app runs in local, so a
 *   config value changed after boot can never switch 2FA off on a server either.
 */
final class TwoFactorSwitch
{
    public static function required(): bool
    {
        return config('edqa.auth.require_two_factor') !== false || ! app()->environment('local');
    }

    /** @throws TwoFactorSwitchRefused */
    public static function assertAllowed(): void
    {
        if (config('edqa.auth.require_two_factor') === false && ! app()->environment('local')) {
            throw TwoFactorSwitchRefused::outsideLocal((string) app()->environment());
        }
    }
}
