<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// /admin/dev/components: the component gallery for reviewing styling against the prototype. Local only,
// and still behind the one auth group (SECURITY.md §3).

beforeEach(function (): void {
    $this->admin = User::factory()->create();
});

it('shows the gallery in the local environment, with the dev and enum strings', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->actingAs($this->admin)->get('/admin/dev/components')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dev/components')
            ->has('translations.dev.title')
            ->has('translations.enums.band.needs_action'));
});

it('does not exist outside the local environment', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    $this->actingAs($this->admin)->get('/admin/dev/components')->assertNotFound();
})->with(['testing', 'staging', 'production']);

it('needs a signed-in administrator even locally', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->get('/admin/dev/components')->assertRedirect('/login');
});

it('shares the dev strings with the gallery only', function (): void {
    $this->actingAs($this->admin)->get('/')
        ->assertInertia(fn (Assert $page) => $page->missing('translations.dev'));
});
