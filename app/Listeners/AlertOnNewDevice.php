<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Notifications\NewDeviceLogin;
use App\Support\Auth\AuthAudit;
use App\Support\Auth\DeviceFingerprint;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * New-device sign-in alerts (SECURITY.md §2), the early warning for a stolen password now that
 * there is no second factor: a sign-in from an IP + browser pair not seen for that administrator
 * in 90 days emails them and every other active administrator, and is audited. Devices are kept
 * as keyed hashes only (DeviceFingerprint).
 */
final class AlertOnNewDevice
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;
        $request = request();
        $fingerprint = DeviceFingerprint::of($request->ip(), $request->userAgent());
        $now = now();

        $lastSeen = DB::table('user_devices')->where('user_id', $user->id)->where('fingerprint', $fingerprint)->value('last_seen_at');
        $known = $lastSeen !== null && $now->copy()->subDays((int) config('edqa.auth.new_device_days'))->lessThanOrEqualTo($lastSeen);

        DB::table('user_devices')->upsert(
            [['user_id' => $user->id, 'fingerprint' => $fingerprint, 'first_seen_at' => $now, 'last_seen_at' => $now]],
            ['user_id', 'fingerprint'],
            ['last_seen_at'],
        );

        if ($known) {
            return;
        }

        $recipients = User::query()->where('is_active', true)->get();
        Notification::send($recipients, new NewDeviceLogin($user, (string) $request->ip(), $request->userAgent(), $now));
        AuthAudit::record('login-new-device', $user, ['notified' => $recipients->count()]);
    }
}
