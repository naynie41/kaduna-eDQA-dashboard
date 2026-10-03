<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * Sign-in events in the audit trail (SECURITY.md §4), log "auth": who, what, IP and user agent.
 * A failed login records the email tried, never the password.
 */
final class AuditAuthenticationEvents
{
    public function handleLogin(Login $event): void
    {
        $this->record('login', $event->user);
    }

    public function handleLogout(Logout $event): void
    {
        $this->record('logout', $event->user);
    }

    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        $this->record('login-failed', $event->user, ['email' => is_string($email) ? $email : null]);
    }

    /** With 'confirm' on, 2FA is active only once confirmed: that is "enabled". */
    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record('two-factor-enabled', $event->user);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record('two-factor-disabled', $event->user);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->record('two-factor-recovery-codes-regenerated', $event->user);
    }

    public function handleRecoveryCodeUsed(RecoveryCodeReplaced $event): void
    {
        $this->record('two-factor-recovery-code-used', $event->user);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(string $event, mixed $user, array $extra = []): void
    {
        $request = request();
        $log = activity('auth')
            ->event($event)
            ->withProperties([...$extra, 'ip' => $request->ip(), 'user_agent' => $request->userAgent()]);

        if ($user instanceof Authenticatable && $user instanceof Model) {
            $log->causedBy($user)->performedOn($user);
        }

        $log->log("auth.{$event}");
    }
}
