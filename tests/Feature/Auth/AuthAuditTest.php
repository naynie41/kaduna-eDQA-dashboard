<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

// SECURITY.md §4: login, logout and failed login are audited (lockouts, unlocks, password changes
// and new-device sign-ins in LockoutTest, PasswordPolicyTest and NewDeviceAlertTest).

function authEntry(string $event): ?Activity
{
    return Activity::query()->where('log_name', 'auth')->where('event', $event)->latest('id')->first();
}

it('records a login and a logout, with who, IP and user agent', function (): void {
    $user = User::factory()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
        ->withHeader('User-Agent', 'AuditBrowser/1.0')
        ->actingAs($user)
        ->post(route('logout'));

    $logout = authEntry('logout');
    expect($logout?->causer?->is($user))->toBeTrue()
        ->and($logout->properties['ip'])->toBe('198.51.100.4')
        ->and($logout->properties['user_agent'])->toBe('AuditBrowser/1.0');
});

it('records a successful login', function (): void {
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    expect(authEntry('login')?->causer?->is($user))->toBeTrue();
});

it('records a failed login without the password', function (): void {
    $user = User::factory()->create(['email' => 'target@example.test']);

    $this->post(route('login'), ['email' => 'target@example.test', 'password' => 'wrong-password-123']);

    $entry = authEntry('login-failed');
    expect($entry)->not->toBeNull()
        ->and($entry->properties['email'])->toBe('target@example.test')
        ->and(json_encode($entry->properties))->not->toContain('wrong-password-123');
});
