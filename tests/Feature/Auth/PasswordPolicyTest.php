<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

// SECURITY.md §2: min 14, mixed case, numbers, symbols, uncompromised (not in testing), and no
// reuse of the last 5. For edqa:admin:create, password reset and password change alike.

/** Meets the policy: a refusal of it can only be the history rule. */
const ORIGINAL = 'Original-Pass-00';

function changePassword(mixed $test, User $user, string $current, string $new): mixed
{
    return $test->actingAs($user)->from(route('admin.security.edit'))->put(route('admin.user-password.update'), [
        'current_password' => $current,
        'password' => $new,
        'password_confirmation' => $new,
    ]);
}

function resetPassword(mixed $test, User $user, string $new): mixed
{
    Notification::fake();
    $test->post(route('password.email'), ['email' => $user->email]);
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    return $test->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => $new, 'password_confirmation' => $new]);
}

it('requires 14+ characters with upper and lower case, a number and a symbol', function (string $password, bool $passes): void {
    expect(validator(['p' => $password], ['p' => Password::defaults()])->passes())->toBe($passes);
})->with([
    '13 characters' => ['Correct-Hor12', false],
    'no upper case' => ['correct-horse-12', false],
    'no lower case' => ['CORRECT-HORSE-12', false],
    'no number' => ['Correct-Horse-Battery', false],
    'no symbol' => ['CorrectHorse1234', false],
    'meets the policy' => ['Correct-Horse-12', true],
]);

it('refuses a password found in breaches outside the test environment', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    $password = 'Correct-Horse-12';
    $hash = strtoupper(sha1($password));
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(substr($hash, 5).":42\r\n")]);

    expect(validator(['p' => $password], ['p' => Password::defaults()])->fails())->toBeTrue();
});

it('applies the policy to edqa:admin:create', function (): void {
    $this->artisan('edqa:admin:create')
        ->expectsQuestion('Name', 'Amina Bello')
        ->expectsQuestion('Email', 'amina@example.test')
        ->expectsQuestion('Password', 'CorrectHorse1234')
        ->expectsQuestion('Confirm password', 'CorrectHorse1234')
        ->assertFailed();

    expect(User::query()->where('email', 'amina@example.test')->exists())->toBeFalse();
});

it('applies the policy to a password reset', function (): void {
    $user = User::factory()->create();

    resetPassword($this, $user, 'correct-horse-12')->assertSessionHasErrors('password');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

it('refuses any of the last 5 passwords on change', function (): void {
    $user = User::factory()->create(['password' => ORIGINAL]);

    changePassword($this, $user, ORIGINAL, 'First-Password-01')->assertSessionHasNoErrors();
    changePassword($this, $user->refresh(), 'First-Password-01', ORIGINAL)->assertSessionHasErrors(['password' => __('auth.password_reused', ['count' => 5])]);
    changePassword($this, $user->refresh(), 'First-Password-01', 'First-Password-01')->assertSessionHasErrors(['password' => __('auth.password_reused', ['count' => 5])]);

    expect(Hash::check('First-Password-01', $user->refresh()->password))->toBeTrue();
});

it('refuses a recent password on reset', function (): void {
    $user = User::factory()->create(['password' => ORIGINAL]);
    changePassword($this, $user, ORIGINAL, 'First-Password-01')->assertSessionHasNoErrors();
    auth()->logout();

    resetPassword($this, $user->refresh(), ORIGINAL)->assertSessionHasErrors(['password' => __('auth.password_reused', ['count' => 5])]);
});

it('allows a password again once 5 newer ones have been used, keeping only 5 hashes', function (): void {
    $user = User::factory()->create(['password' => ORIGINAL]);
    $current = ORIGINAL;
    foreach (range(1, 5) as $n) {
        $next = "Newer-Password-0{$n}";
        changePassword($this, $user->refresh(), $current, $next)->assertSessionHasNoErrors();
        $current = $next;
    }

    expect(DB::table('password_histories')->where('user_id', $user->id)->count())->toBe(5);
    changePassword($this, $user->refresh(), $current, ORIGINAL)->assertSessionHasNoErrors();
});

it('stores hashes only, never the password', function (): void {
    $user = User::factory()->create(['password' => ORIGINAL]);
    changePassword($this, $user, ORIGINAL, 'First-Password-01');

    $stored = DB::table('password_histories')->where('user_id', $user->id)->pluck('password_hash');
    expect($stored->contains('First-Password-01'))->toBeFalse()
        ->and($stored->contains(fn (string $hash): bool => Hash::check('First-Password-01', $hash)))->toBeTrue();
});

it('audits a password change and a reset, without the password', function (): void {
    $user = User::factory()->create(['password' => ORIGINAL]);

    changePassword($this, $user, ORIGINAL, 'First-Password-01')->assertSessionHasNoErrors();
    $changed = Activity::query()->where('log_name', 'auth')->where('event', 'password-changed')->sole();
    expect($changed->causer?->is($user))->toBeTrue()
        ->and($changed->properties['via'])->toBe('settings')
        ->and(json_encode($changed->properties))->not->toContain('First-Password-01');

    auth()->logout();
    resetPassword($this, $user->refresh(), 'Second-Password-02')->assertSessionHasNoErrors();
    $reset = Activity::query()->where('log_name', 'auth')->where('event', 'password-changed')->latest('id')->first();
    expect($reset?->properties['via'])->toBe('reset')
        ->and($reset?->subject?->is($user))->toBeTrue();
});
