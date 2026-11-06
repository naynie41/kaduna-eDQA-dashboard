<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * A sign-in's device as a keyed hash of its IP and user agent (SECURITY.md §2): enough to tell a
 * known device from a new one, without storing either value.
 */
final class DeviceFingerprint
{
    public static function of(?string $ip, ?string $userAgent): string
    {
        return hash_hmac('sha256', ($ip ?? '')."\n".($userAgent ?? ''), (string) config('app.key'));
    }
}
