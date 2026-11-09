<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

// SECURITY.md §2: the tables behind the controls that stand in for MFA (D-84).

function deviceRow(int $userId, string $fingerprint): array
{
    return ['user_id' => $userId, 'fingerprint' => $fingerprint, 'first_seen_at' => now(), 'last_seen_at' => now()];
}

it('keeps one row per user and device fingerprint', function (): void {
    $user = User::factory()->create();
    DB::table('user_devices')->insert(deviceRow($user->id, str_repeat('a', 64)));

    expectUniqueViolation(
        fn () => DB::table('user_devices')->insert(deviceRow($user->id, str_repeat('a', 64))),
        'user_devices_user_id_fingerprint_unique',
    );
});

it('stores a fingerprint as a 64-character hex hash, never a raw value', function (string $fingerprint): void {
    $user = User::factory()->create();

    expectCheckViolation(
        fn () => DB::table('user_devices')->insert(deviceRow($user->id, $fingerprint)),
        'user_devices_fingerprint_hex',
    );
})->with([
    'an IP address' => [str_pad('203.0.113.20', 64)],
    'upper case' => [str_repeat('A', 64)],
]);

it('drops a user\'s devices and password history with the user', function (): void {
    $user = User::factory()->create();
    DB::table('user_devices')->insert(deviceRow($user->id, str_repeat('b', 64)));

    $user->delete();

    expect(DB::table('user_devices')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_histories')->where('user_id', $user->id)->count())->toBe(0);
});
