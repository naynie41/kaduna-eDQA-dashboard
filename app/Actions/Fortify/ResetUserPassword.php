<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Support\Auth\PasswordChange;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * A forgotten password, reset from the emailed link (SECURITY.md §2): the policy and history
 * apply, every session of the account ends, and the change is audited.
 */
final class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules($user),
        ])->validate();

        PasswordChange::apply($user, $input['password'], 'reset');
    }
}
