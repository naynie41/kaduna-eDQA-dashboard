<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

// Fortify's password reset by email link. The password policy has its own tests.

it('renders the forgot-password screen', function (): void {
    $this->get(route('password.request'))->assertOk();
});

it('emails a reset link', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('renders the reset screen from the emailed link', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification): bool {
        $this->get(route('password.reset', $notification->token))->assertOk();

        return true;
    });
});

it('resets the password with a valid token, then sends the administrator to sign in', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            // Meets the SECURITY.md §2 policy: 12+ characters, mixed case, a number.
            'password' => 'Correct-Horse-12',
            'password_confirmation' => 'Correct-Horse-12',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        return true;
    });
});

it('refuses a reset with an invalid token', function (): void {
    $user = User::factory()->create();

    $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'Correct-Horse-12',
        'password_confirmation' => 'Correct-Horse-12',
    ])->assertSessionHasErrors('email');
});
