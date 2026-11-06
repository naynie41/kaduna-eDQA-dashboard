<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A new password may not be any of the user's last 5 (SECURITY.md §2), the current one included. */
final readonly class NotRecentlyUsed implements ValidationRule
{
    public function __construct(private User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && PasswordHistory::wasUsed($this->user, $value)) {
            $fail(__('auth.password_reused', ['count' => PasswordHistory::size()]));
        }
    }
}
