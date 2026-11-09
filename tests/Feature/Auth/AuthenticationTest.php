<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

// Fortify's sign-in and sign-out (SECURITY.md §2). The lockout and alerts have their own tests.

it('renders the sign-in screen', function (): void {
    $this->get(route('login'))->assertOk();
});

it('signs an administrator in with a password and sends them to /admin', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user);
});

it('does not sign in with a wrong password', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('signs out to the public dashboard', function (): void {
    $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect('/');

    $this->assertGuest();
});

it('rate limits sign-in attempts per email and IP', function (): void {
    $user = User::factory()->create();
    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertTooManyRequests();
});
