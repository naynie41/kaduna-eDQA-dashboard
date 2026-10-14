<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Explicit audit entries for domain actions that need more than a model's own change log: a
 * typed reason, a named event, and old/new values chosen by the action (SECURITY.md §4).
 * Every entry carries the request context, exactly as model-driven entries do (Audited).
 */
final class AuditLog
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $extra
     */
    public static function record(Model $subject, ?User $causer, string $event, array $old, array $new, ?string $reason = null, array $extra = []): void
    {
        $logger = activity()->performedOn($subject)->event($event);
        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        $logger->withProperties([
            'old' => $old,
            'attributes' => $new,
            'reason' => $reason,
            ...$extra,
            ...self::requestContext(),
        ])->log($event);
    }

    /**
     * IP and user agent for a routed request; source = console otherwise (Laravel's synthetic
     * console request has no route, IP 127.0.0.1 and user agent "Symfony").
     *
     * @return array<string, string|null>
     */
    public static function requestContext(): array
    {
        $request = request();

        return app()->runningInConsole() && $request->route() === null
            ? ['source' => 'console']
            : ['ip' => $request->ip(), 'user_agent' => $request->userAgent()];
    }
}
