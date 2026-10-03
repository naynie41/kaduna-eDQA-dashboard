<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the security page to an administrator with 2FA, after password confirmation', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('password.confirm'));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('recoveryCodes', null));
});

it('changes the password to one that meets the policy', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'Correct-Horse-12',
            'password_confirmation' => 'Correct-Horse-12',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status')
        ->assertRedirect(route('security.edit'));

    expect(Hash::check('Correct-Horse-12', $user->refresh()->password))->toBeTrue();
});

it('needs the correct current password', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'Correct-Horse-12',
            'password_confirmation' => 'Correct-Horse-12',
        ])
        ->assertSessionHasErrors('current_password');
});

it('refuses a new password that breaks the policy', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasErrors('password');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

it('tells the page whether 2FA is on for the user', function (bool $withTwoFactor): void {
    config(['edqa.auth.require_two_factor' => false]);
    $factory = User::factory();
    $user = ($withTwoFactor ? $factory->withTwoFactor() : $factory)->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('twoFactorEnabled', $withTwoFactor));
})->with(['2FA on' => [true], '2FA off' => [false]]);
