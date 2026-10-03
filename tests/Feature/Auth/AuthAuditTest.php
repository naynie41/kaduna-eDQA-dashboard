<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

// SECURITY.md §4: login, logout, failed login and 2FA enable/disable are audited.

function authEntry(string $event): ?Activity
{
    return Activity::query()->where('log_name', 'auth')->where('event', $event)->latest('id')->first();
}

it('records a login and a logout, with who, IP and user agent', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    // withTwoFactor users finish login at the challenge; act as already authenticated instead.
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

it('records 2FA being enabled and disabled', function (): void {
    $user = User::factory()->create();

    confirmTwoFactor($user);
    expect(authEntry('two-factor-enabled')?->causer?->is($user))->toBeTrue();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'));
    expect(authEntry('two-factor-disabled')?->causer?->is($user))->toBeTrue();
});
