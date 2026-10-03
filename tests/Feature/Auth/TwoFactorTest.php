<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\RecoveryCodes;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

// SECURITY.md §2: TOTP for every account; recovery codes shown once, stored hashed.

it('confirms 2FA, then shows the recovery codes once and stores only their hashes', function (): void {
    $user = User::factory()->create();

    [$response] = confirmTwoFactor($user);

    $plain = session('recovery_codes');
    expect($plain)->toBeArray()->toHaveCount(8)
        ->and($user->refresh()->two_factor_confirmed_at)->not->toBeNull();

    $stored = (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');
    foreach ($plain as $code) {
        expect($stored)->not->toContain($code)->toContain(RecoveryCodes::hash($code));
    }
});

it('never shows the recovery codes again', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson('/user/two-factor-recovery-codes')
        ->assertNotFound();
});

it('regenerates recovery codes, showing the new ones once', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $before = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.regenerate-recovery-codes'))
        ->assertRedirect();

    expect(session('recovery_codes'))->toHaveCount(8)
        ->and($user->refresh()->two_factor_recovery_codes)->not->toBe($before);
});

it('signs in with a recovery code, which then cannot be reused', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect('/');
    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'));
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

it('signs in with a valid TOTP code', function (): void {
    $user = User::factory()->create();
    [, $secret] = confirmTwoFactor($user);
    $this->post(route('logout'));

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    // A fresh code: the one used to confirm setup cannot be replayed.
    $this->travel(1)->minutes();
    $this->post(route('two-factor.login.store'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('regenerates the session when 2FA is confirmed', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('two-factor.setup'));
    $before = session()->getId();

    confirmTwoFactor($user);

    expect(session()->getId())->not->toBe($before);
});

it('throttles the 2FA challenge to 5 attempts a minute', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('two-factor.login.store'), ['code' => '000000']);
    }

    $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertTooManyRequests();
});
