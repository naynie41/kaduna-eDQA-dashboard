<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use App\Support\Auth\NotRecentlyUsed;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * The password policy (SECURITY.md §2, Password::defaults), plus no reuse of the user's last 5
     * when there is a user (a reset or a change).
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(?User $user = null): array
    {
        return ['required', 'string', Password::default(), 'confirmed', ...($user !== null ? [new NotRecentlyUsed($user)] : [])];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
