<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Auth\RecoveryCodes;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication as FortifyConfirmTwoFactorAuthentication;

/**
 * Confirms 2FA setup with a TOTP code (Fortify), then issues the recovery codes: stored as
 * hashes, flashed to the session to be shown exactly once. The session ID is regenerated, as
 * the account's authentication strength has just changed (SECURITY.md §2).
 */
final class ConfirmTwoFactorAuthentication extends FortifyConfirmTwoFactorAuthentication
{
    /**
     * @param  User  $user
     * @param  string  $code
     */
    public function __invoke($user, $code): void
    {
        parent::__invoke($user, $code);   // throws a validation error for a wrong code

        $codes = RecoveryCodes::generate();
        $user->forceFill(['two_factor_recovery_codes' => RecoveryCodes::store($codes)])->save();

        $session = request()->session();
        $session->regenerate();
        $session->flash('recovery_codes', $codes);
    }
}
