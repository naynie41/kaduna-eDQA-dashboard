<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication as FortifyEnableTwoFactorAuthentication;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;

/**
 * Starts 2FA setup: a new TOTP secret, and no recovery codes yet. Codes are generated, shown
 * once and stored hashed only when setup is confirmed (ConfirmTwoFactorAuthentication).
 */
final class EnableTwoFactorAuthentication extends FortifyEnableTwoFactorAuthentication
{
    /**
     * @param  User  $user
     * @param  bool  $force
     */
    public function __invoke($user, $force = false): void
    {
        if (empty($user->two_factor_secret) || $force === true) {
            // The provider's default secret length (16 base32 characters, 80 bits).
            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt($this->provider->generateSecretKey()),
                'two_factor_recovery_codes' => null,
            ])->save();

            TwoFactorAuthenticationEnabled::dispatch($user);
        }
    }
}
