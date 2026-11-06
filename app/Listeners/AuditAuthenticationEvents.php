<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Support\Auth\AuthAudit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Sign-in events in the audit trail (SECURITY.md §4), log "auth": who, what, IP and user agent.
 * A failed login records the email tried, never the password. Lockouts, unlocks, password changes
 * and new-device sign-ins are recorded where they happen (App\Support\Auth).
 */
final class AuditAuthenticationEvents
{
    public function handleLogin(Login $event): void
    {
        AuthAudit::record('login', $event->user instanceof User ? $event->user : null);
    }

    /** A logout forced by the 12-hour limit says so (EnforceAbsoluteSessionLifetime). */
    public function handleLogout(Logout $event): void
    {
        $reason = request()->attributes->get('logout_reason');
        AuthAudit::record('logout', $event->user instanceof User ? $event->user : null, is_string($reason) ? ['reason' => $reason] : []);
    }

    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        AuthAudit::record('login-failed', $event->user instanceof User ? $event->user : null, ['email' => is_string($email) ? $email : null]);
    }
}
