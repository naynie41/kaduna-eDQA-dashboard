<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;

// D-35: no multi-factor authentication anywhere. The compensating controls are SECURITY.md §2.

it('registers no two-factor route', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_contains($route->uri(), 'two-factor')
            || str_contains((string) $route->getName(), 'two-factor')
            || str_contains($route->uri(), 'recovery-code'))
        ->map(fn (RoutingRoute $route): string => $route->uri())
        ->values()->all();

    expect($routes)->toBe([])
        ->and(Features::enabled(Features::twoFactorAuthentication()))->toBeFalse();

    $this->get('/two-factor-challenge')->assertNotFound();
});

it('has no two-factor middleware on any route', function (): void {
    expect(app('router')->getMiddleware())->not->toHaveKey('two-factor.confirmed');

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            expect((string) $middleware)->not->toContain('two-factor')->not->toContain('TwoFactor');
        }
    }
});

it('has no two-factor columns on users', function (): void {
    expect(Schema::hasColumns('users', ['two_factor_secret']))->toBeFalse()
        ->and(Schema::hasColumns('users', ['two_factor_recovery_codes']))->toBeFalse()
        ->and(Schema::hasColumns('users', ['two_factor_confirmed_at']))->toBeFalse();
});

it('has no two-factor page, component or code', function (): void {
    $paths = collect([
        ...File::allFiles(app_path()),
        ...File::allFiles(resource_path('js/pages')),
        ...File::allFiles(resource_path('js/components')),
    ])->map(fn ($file): string => $file->getRelativePathname());

    expect($paths->filter(fn (string $path): bool => (bool) preg_match('/two.?factor|recovery.?code/i', $path))->values()->all())
        ->toBe([]);
    expect(class_uses_recursive(User::class))->not->toContain('Laravel\Fortify\TwoFactorAuthenticatable');
});

it('signs an administrator in with email and password alone', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user);
    $this->get('/admin/sources')->assertOk();
});
