<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Audit\AuditLog;

/**
 * Sign-in events in the audit trail (SECURITY.md §4), log "auth": who, what, and the request's IP
 * and user agent (or source = console for an artisan command). Never a password.
 */
final class AuthAudit
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  bool  $byUser  false when the user is only the subject (an unlock from the console)
     * @param  array<string, string>|null  $context  where it came from, when the caller knows better than
     *                                               the current request (a console command)
     */
    public static function record(string $event, ?User $user, array $extra = [], bool $byUser = true, ?array $context = null): void
    {
        $log = activity('auth')
            ->event($event)
            ->withProperties([...$extra, ...($context ?? AuditLog::requestContext())]);

        if ($user !== null) {
            $log->performedOn($user);
            if ($byUser) {
                $log->causedBy($user);
            }
        }

        $log->log("auth.{$event}");
    }
}
