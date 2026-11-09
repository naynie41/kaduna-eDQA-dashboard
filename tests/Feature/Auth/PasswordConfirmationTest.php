<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// Fortify's confirm-password screen, for a signed-in administrator only.

it('renders the confirm-password screen to a signed-in administrator', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/confirm-password'));
});

it('sends a guest to sign in instead', function (): void {
    $this->get(route('password.confirm'))->assertRedirect(route('login'));
});
