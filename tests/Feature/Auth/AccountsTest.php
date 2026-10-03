<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Config\EnvFlag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

// ---- edqa:admin:create (SECURITY.md §2: accounts by artisan command only)

it('creates a verified, active administrator interactively', function (): void {
    $this->artisan('edqa:admin:create')
        ->expectsQuestion('Name', 'Amina Bello')
        ->expectsQuestion('Email', 'amina@example.test')
        ->expectsQuestion('Password', 'Correct-Horse-12')
        ->expectsQuestion('Confirm password', 'Correct-Horse-12')
        ->expectsOutputToContain('amina@example.test')
        ->assertSuccessful();

    $user = User::query()->where('email', 'amina@example.test')->sole();
    expect($user->name)->toBe('Amina Bello')
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and(Hash::check('Correct-Horse-12', $user->password))->toBeTrue();

    $entry = Activity::query()->forSubject($user)->where('event', 'created')->sole();
    expect($entry->properties['attributes'])->toMatchArray(['email' => 'amina@example.test'])
        ->and($entry->properties['source'])->toBe('console');
});

it('rejects a weak password and a duplicate email', function (): void {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->artisan('edqa:admin:create')
        ->expectsQuestion('Name', 'X')
        ->expectsQuestion('Email', 'taken@example.test')
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->assertFailed();

    expect(User::query()->where('email', 'taken@example.test')->count())->toBe(1);
});

it('warns when more than three administrators are active', function (): void {
    User::factory()->count(3)->create();

    $this->artisan('edqa:admin:create')
        ->expectsQuestion('Name', 'Fourth Admin')
        ->expectsQuestion('Email', 'fourth@example.test')
        ->expectsQuestion('Password', 'Correct-Horse-12')
        ->expectsQuestion('Confirm password', 'Correct-Horse-12')
        ->expectsOutputToContain('4 active administrators')
        ->assertSuccessful();
});

// ---- edqa:admin:disable

it('disables an administrator, ends their sessions and records it', function (): void {
    $user = User::factory()->withTwoFactor()->create(['email' => 'leaver@example.test']);
    DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->artisan('edqa:admin:disable', ['email' => 'leaver@example.test'])->assertSuccessful();

    expect($user->refresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(Activity::query()->forSubject($user)->where('event', 'updated')->sole()->properties['attributes'])
        ->toBe(['is_active' => false]);
});

it('fails for an unknown email', function (): void {
    $this->artisan('edqa:admin:disable', ['email' => 'nobody@example.test'])->assertFailed();
});

it('refuses to log a disabled administrator in', function (): void {
    $user = User::factory()->withTwoFactor()->create(['is_active' => false]);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

// ---- Password policy and session cookie (SECURITY.md §2)

it('requires 12+ characters with mixed case and numbers', function (): void {
    $rule = Password::defaults();

    expect(validator(['p' => 'Short1a'], ['p' => $rule])->fails())->toBeTrue()
        ->and(validator(['p' => 'alllowercase123'], ['p' => $rule])->fails())->toBeTrue()
        ->and(validator(['p' => 'NoNumbersHereAtAll'], ['p' => $rule])->fails())->toBeTrue()
        ->and(validator(['p' => 'Correct-Horse-12'], ['p' => $rule])->passes())->toBeTrue();
});

it('issues a Secure, HttpOnly, SameSite=Lax session cookie, encrypted, for 8 hours', function (): void {
    expect(config('session.encrypt'))->toBeTrue()
        ->and(config('session.lifetime'))->toBe(480);

    config(['session.secure' => true]);
    $cookie = collect($this->get(route('login'))->headers->getCookies())
        ->first(fn ($c): bool => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('makes the session cookie Secure unless SESSION_SECURE_COOKIE=false', function (string $value, bool $secure): void {
    expect(EnvFlag::enabledUnlessFalse($value))->toBe($secure);
})->with([
    'unset' => ['', true],
    'true' => ['true', true],
    'false (local http only)' => ['false', false],
]);
