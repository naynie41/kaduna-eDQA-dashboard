<?php

declare(strict_types=1);

namespace App\Support\Security;

use RuntimeException;

/** EDQA_REQUIRE_2FA=false outside APP_ENV=local: the app does not start (SECURITY.md §2). */
final class TwoFactorSwitchRefused extends RuntimeException
{
    public static function outsideLocal(string $environment): self
    {
        return new self((string) __('auth.two_factor.switch_refused', ['environment' => $environment]));
    }
}
