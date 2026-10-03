<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Auth\RecoveryCodes;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes as FortifyGenerateNewRecoveryCodes;
use Laravel\Fortify\Events\RecoveryCodesGenerated;

/**
 * Replaces all recovery codes: new hashes stored, the plain codes flashed to be shown once.
 */
final class GenerateNewRecoveryCodes extends FortifyGenerateNewRecoveryCodes
{
    /**
     * @param  User  $user
     */
    public function __invoke($user): void
    {
        $codes = RecoveryCodes::generate();
        $user->forceFill(['two_factor_recovery_codes' => RecoveryCodes::store($codes)])->save();

        request()->session()->flash('recovery_codes', $codes);

        RecoveryCodesGenerated::dispatch($user);
    }
}
