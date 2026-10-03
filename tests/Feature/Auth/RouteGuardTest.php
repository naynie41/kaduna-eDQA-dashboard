<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

// SECURITY.md §3: one protected group. Only these may be reached without logging in.
const PUBLIC_ROUTES = [
    'GET|HEAD login',
    'POST login',
    'GET|HEAD two-factor-challenge',
    'POST two-factor-challenge',
    'GET|HEAD forgot-password',
    'POST forgot-password',
    'GET|HEAD reset-password/{token}',
    'POST reset-password',
    'POST webhooks/odk',
    'GET|HEAD up',
];

/**
 * @return list<RoutingRoute>
 */
function appRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        // Framework internals that are not part of the app's surface.
        fn (RoutingRoute $route): bool => ! str_starts_with($route->uri(), '_') && ! str_starts_with($route->uri(), 'sanctum/'),
    ));
}

function routeKey(RoutingRoute $route): string
{
    return implode('|', $route->methods()).' '.$route->uri();
}

it('keeps the public allow-list exact: every listed route exists', function (): void {
    $registered = array_map(routeKey(...), appRoutes());

    expect(array_diff(PUBLIC_ROUTES, $registered))->toBe([]);
});

it('refuses every other route to an unauthenticated visitor', function (): void {
    $checked = 0;

    foreach (appRoutes() as $route) {
        if (in_array(routeKey($route), PUBLIC_ROUTES, true)) {
            continue;
        }

        $uri = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');
        $method = $route->methods()[0];
        $response = $this->call($method, $uri);
        $status = $response->getStatusCode();

        // Unauthenticated: redirected to login (pages and forms) or 401 (JSON).
        $refused = $status === 401 || ($status === 302 && str_ends_with((string) $response->headers->get('Location'), '/login'));
        expect($refused)->toBeTrue("{$method} {$uri} answered {$status} to an unauthenticated visitor");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(10);
});

it('has no registration routes', function (): void {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register', ['email' => 'x@example.test'])->assertNotFound();
});

it('answers the ODK webhook stub with 501 until ingestion is built', function (): void {
    $this->postJson('/webhooks/odk', [])->assertStatus(501);
});

// ---- Mandatory 2FA (SECURITY.md §2)

it('sends a user without confirmed 2FA to the setup page, not the dashboard', function (): void {
    $user = User::factory()->create(['two_factor_confirmed_at' => null]);

    $this->actingAs($user)->get('/')->assertRedirect(route('two-factor.setup'));
    $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('two-factor.setup'));
});

it('lets a user without 2FA reach setup and log out', function (): void {
    $user = User::factory()->create(['two_factor_confirmed_at' => null]);

    // Setup asks for the password first, as Fortify's 2FA routes do.
    $this->actingAs($user)->get(route('two-factor.setup'))->assertRedirect(route('password.confirm'));
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('two-factor.setup'))->assertOk();
    $this->actingAs($user)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('lets a user with confirmed 2FA reach the dashboard', function (): void {
    $this->actingAs(User::factory()->withTwoFactor()->create())->get('/')->assertOk();
});
