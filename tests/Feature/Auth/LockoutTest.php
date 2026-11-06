<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

// SECURITY.md §2: progressive lockout per account. 5 failures lock it for 15 minutes, 10 for an
// hour, 20 until `edqa:admin:unlock`. The per-IP throttle stays. Every response reads the same
// whether or not the email exists. Failed logins, lockouts and unlocks are audited.

/** One sign-in attempt, each from its own address so the per-IP throttle stays out of the way. */
function attempt(mixed $test, string $email, string $password): mixed
{
    static $n = 0;
    $n++;

    return $test->withServerVariables(['REMOTE_ADDR' => '198.51.'.intdiv($n, 250).'.'.($n % 250 + 1)])
        ->post(route('login.store'), ['email' => $email, 'password' => $password]);
}

function failTimes(mixed $test, User $user, int $times): void
{
    foreach (range(1, $times) as $_) {
        attempt($test, $user->email, 'Wrong-Password-99');
    }
}

function auditEntries(string $event): mixed
{
    return Activity::query()->with(['subject', 'causer'])->where('log_name', 'auth')->where('event', $event)->orderBy('id')->get();
}

it('does not lock before the 5th failure', function (): void {
    $user = User::factory()->create();
    failTimes($this, $user, 4);

    attempt($this, $user->email, 'password');
    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->failed_login_count)->toBe(0);
});

it('locks for 15 minutes after 5 failures, refusing even the right password', function (): void {
    $user = User::factory()->create();
    failTimes($this, $user, 5);

    expect($user->refresh()->locked_until?->diffInMinutes(now(), true))->toEqualWithDelta(15, 0.1);

    attempt($this, $user->email, 'password')->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->travel(16)->minutes();
    attempt($this, $user->email, 'password');
    $this->assertAuthenticatedAs($user);
});

it('locks for an hour after 10 failures', function (): void {
    $user = User::factory()->create();
    failTimes($this, $user, 5);
    $this->travel(16)->minutes();
    failTimes($this, $user, 5);

    expect($user->refresh()->failed_login_count)->toBe(10)
        ->and($user->locked_until?->diffInMinutes(now(), true))->toEqualWithDelta(60, 0.1);

    $this->travel(59)->minutes();
    attempt($this, $user->email, 'password');
    $this->assertGuest();

    $this->travel(2)->minutes();
    attempt($this, $user->email, 'password');
    $this->assertAuthenticatedAs($user);
});

it('does not count attempts made while locked', function (): void {
    $user = User::factory()->create();
    failTimes($this, $user, 5);
    failTimes($this, $user, 3);

    expect($user->refresh()->failed_login_count)->toBe(5);
});

it('locks until an administrator unlocks it after 20 failures', function (): void {
    $user = User::factory()->create(['email' => 'locked@example.test']);
    foreach ([5, 5, 5, 5] as $batch) {
        failTimes($this, $user, $batch);
        $this->travel(2)->hours();
    }

    expect($user->refresh()->locked_out_at)->not->toBeNull();

    $this->travel(30)->days();
    attempt($this, $user->email, 'password');
    $this->assertGuest();

    $this->artisan('edqa:admin:unlock', ['email' => 'locked@example.test'])->assertSuccessful();

    expect($user->refresh()->failed_login_count)->toBe(0)
        ->and($user->locked_until)->toBeNull()
        ->and($user->locked_out_at)->toBeNull();
    attempt($this, $user->email, 'password');
    $this->assertAuthenticatedAs($user);
});

it('resets the count after a successful sign-in', function (): void {
    $user = User::factory()->create();
    failTimes($this, $user, 4);
    attempt($this, $user->email, 'password');
    auth()->logout();
    failTimes($this, $user, 4);

    expect($user->refresh()->failed_login_count)->toBe(4)
        ->and($user->locked_until)->toBeNull();
});

it('answers the same for an unknown email, a wrong password and a locked account', function (): void {
    $user = User::factory()->create();
    $locked = User::factory()->create();
    failTimes($this, $locked, 5);

    $same = ['email' => __('auth.failed')];
    attempt($this, 'nobody@example.test', 'Wrong-Password-99')->assertSessionHasErrors($same);
    attempt($this, $user->email, 'Wrong-Password-99')->assertSessionHasErrors($same);
    attempt($this, $locked->email, 'password')->assertSessionHasErrors($same);
    $this->assertGuest();
});

it('keeps the per-IP throttle', function (): void {
    $user = User::factory()->create();
    $same = ['REMOTE_ADDR' => '203.0.113.50'];
    foreach (range(1, 5) as $_) {
        $this->withServerVariables($same)->post(route('login.store'), ['email' => $user->email, 'password' => 'Wrong-Password-99']);
    }

    $this->withServerVariables($same)->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertStatus(429);
    $this->assertGuest();
});

it('audits failed logins, each lockout and the unlock', function (): void {
    $user = User::factory()->create(['email' => 'audited@example.test']);
    failTimes($this, $user, 5);
    $this->travel(16)->minutes();
    failTimes($this, $user, 5);
    $this->artisan('edqa:admin:unlock', ['email' => 'audited@example.test'])->assertSuccessful();

    expect(auditEntries('login-failed'))->toHaveCount(10);

    $lockouts = auditEntries('lockout');
    expect($lockouts)->toHaveCount(2)
        ->and($lockouts[0]->subject?->is($user))->toBeTrue()
        ->and($lockouts[0]->properties['failures'])->toBe(5)
        ->and($lockouts[0]->properties['minutes'])->toBe(15)
        ->and($lockouts[1]->properties['failures'])->toBe(10)
        ->and($lockouts[1]->properties['minutes'])->toBe(60);

    $unlock = auditEntries('unlock')->sole();
    expect($unlock->subject?->is($user))->toBeTrue()
        ->and($unlock->properties['source'])->toBe('console');
});

it('audits the indefinite lockout with no end time', function (): void {
    $user = User::factory()->create();
    foreach ([5, 5, 5, 5] as $batch) {
        failTimes($this, $user, $batch);
        $this->travel(2)->hours();
    }

    expect(auditEntries('lockout')->last()->properties['minutes'])->toBeNull()
        ->and(auditEntries('lockout')->last()->properties['failures'])->toBe(20);
});

it('fails to unlock an unknown email', function (): void {
    $this->artisan('edqa:admin:unlock', ['email' => 'nobody@example.test'])->assertFailed();
});
