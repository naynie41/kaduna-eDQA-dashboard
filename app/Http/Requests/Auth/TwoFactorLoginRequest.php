<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Auth\RecoveryCodes;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;

/**
 * Fortify's 2FA challenge request, matching a recovery code against the stored hashes.
 * Fortify then calls User::replaceRecoveryCode() with the matched hash, which consumes it.
 */
final class TwoFactorLoginRequest extends FortifyTwoFactorLoginRequest
{
    public function validRecoveryCode(): ?string
    {
        if (! is_string($this->recovery_code) || $this->recovery_code === '') {
            return null;
        }

        $user = $this->challengedUser();
        if (! $user instanceof User) {
            return null;
        }

        $hash = RecoveryCodes::hash(trim($this->recovery_code));
        foreach ($user->recoveryCodes() as $stored) {
            if (hash_equals($stored, $hash)) {
                $this->session()->forget('login.id');

                return $stored;
            }
        }

        return null;
    }
}
