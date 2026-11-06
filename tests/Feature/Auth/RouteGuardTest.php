<?php

declare(strict_types=1);

use App\Http\Middleware\ForgetRememberMe;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

// SECURITY.md §3: every route is public, admin, webhook, health or auth. An unclassified route
// fails here. Admin routes need a signed-in administrator (no MFA, D-84); public routes are
// read-only; every response says noindex (D-82); no public page names /login or /admin (D-78).

/** Fortify's screens and actions: reachable signed out (or acting on a pending sign-in). */
const AUTH_URIS = [
    'login', 'logout', 'forgot-password', 'reset-password', 'reset-password/{token}',
    'email/verify', 'email/verify/{id}/{hash}', 'email/verification-notification',
    'user/confirm-password', 'user/confirmed-password-status',
];

/** The public dashboard (ARCHITECTURE.md §9.0). */
const PUBLIC_PAGES = ['/', '/facilities', '/lgas', '/monitor', '/visuals', '/plan'];

/** The old signed-in URLs, now permanent redirects to the public ones. */
const OLD_URLS = [
    '/dashboard' => '/',
    '/dashboard/home' => '/',
    '/dashboard/facility' => '/facilities',
    '/dashboard/lga' => '/lgas',
    '/dashboard/monitor' => '/monitor',
    '/dashboard/visuals' => '/visuals',
    '/dashboard/plan' => '/plan',
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

/** @return list<string> */
function middlewareOf(RoutingRoute $route): array
{
    return array_values(array_map(strval(...), $route->gatherMiddleware()));
}

function classify(RoutingRoute $route): ?string
{
    $uri = $route->uri();
    $middleware = middlewareOf($route);

    return match (true) {
        $uri === 'up' => 'health',
        $uri === 'webhooks/{source}' => 'webhook',
        in_array($uri, AUTH_URIS, true) => 'auth',
        ($uri === 'admin' || str_starts_with($uri, 'admin/')) && str_starts_with((string) $route->getName(), 'admin') && in_array('auth', $middleware, true) => 'admin',
        str_starts_with((string) $route->getName(), 'public.') && in_array('public.readonly', $middleware, true) => 'public',
        default => null,
    };
}

/** @return list<RoutingRoute> */
function routesOf(string $kind): array
{
    return array_values(array_filter(appRoutes(), fn (RoutingRoute $route): bool => classify($route) === $kind));
}

function concreteUri(RoutingRoute $route): string
{
    return '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');
}

// ---- Classification

it('classifies every registered route as public, admin, webhook, health or auth', function (): void {
    $unclassified = array_values(array_map(routeKey(...), array_filter(appRoutes(), fn (RoutingRoute $route): bool => classify($route) === null)));

    expect($unclassified)->toBe([]);
});

it('has every kind of route, and the public pages and old URLs it should', function (): void {
    foreach (['public', 'admin', 'webhook', 'health', 'auth'] as $kind) {
        expect(routesOf($kind))->not->toBeEmpty("no {$kind} route");
    }

    $public = array_map(fn (RoutingRoute $route): string => '/'.ltrim($route->uri(), '/'), routesOf('public'));
    // The old URLs are one route, /dashboard/{page} (their redirects are tested below).
    expect(array_diff([...PUBLIC_PAGES, '/dashboard', '/dashboard/{page}'], $public))->toBe([]);
});

it('guards admin routes with the allow-list first, then auth, and nothing for MFA (D-84)', function (): void {
    foreach (routesOf('admin') as $route) {
        $middleware = middlewareOf($route);
        $ip = array_search('admin.ip', $middleware, true);

        expect($ip)->not->toBeFalse("{$route->uri()} has no admin.ip")
            ->and($ip)->toBeLessThan(array_search('auth', $middleware, true));
        expect($middleware)->not->toContain('verified')->not->toContain('two-factor.confirmed');
    }
});

it('keeps public routes read-only, rate limited, signed out and off the allow-list', function (): void {
    foreach (routesOf('public') as $route) {
        expect(middlewareOf($route))->toContain('public.readonly')->toContain('throttle:public')
            ->not->toContain('auth')->not->toContain('admin.ip')
            ->and($route->methods())->toBe(['GET', 'HEAD']);
    }
});

it('puts the sign-in and reset screens behind the allow-list, without remember-me', function (): void {
    foreach (routesOf('auth') as $route) {
        expect(middlewareOf($route))->toContain('admin.ip')->toContain(ForgetRememberMe::class);
    }
});

// ---- Admin

it('redirects a guest from every admin route to /login (or 401 for JSON)', function (): void {
    foreach (routesOf('admin') as $route) {
        $method = $route->methods()[0];
        $response = $this->call($method, concreteUri($route));
        $status = $response->getStatusCode();

        $refused = $status === 401 || ($status === 302 && str_ends_with((string) $response->headers->get('Location'), '/login'));
        expect($refused)->toBeTrue("{$method} {$route->uri()} answered {$status} to a guest");
    }
});

it('lets a signed-in administrator in with a password alone (no MFA, D-84)', function (): void {
    $this->actingAs(User::factory()->create())->get('/admin/sources')->assertOk();
});

it('sends an administrator to /admin after sign-in, and to the public dashboard after sign-out', function (): void {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/admin');
    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

it('keeps the admin JSON under /admin/api, and nothing at the old /api paths', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/admin/api/sources')->assertOk();
    $this->actingAs($user)->getJson('/api/sources')->assertNotFound();
    $this->actingAs($user)->getJson('/api/facilities/1')->assertNotFound();
});

// ---- Public

it('answers 200 to a guest for every public page', function (string $path): void {
    $this->get($path)->assertOk();
    $this->assertGuest();
})->with(PUBLIC_PAGES);

it('answers 405 to anything but GET and HEAD on a public page', function (string $path, string $method): void {
    $this->call($method, $path)->assertStatus(405);
})->with(PUBLIC_PAGES)->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('sends the old /dashboard URLs to the public ones for good, keeping the filters', function (string $old, string $new): void {
    $this->get($old.'?year=2026&lga=ZAR')
        ->assertStatus(301)
        ->assertRedirect($new.'?year=2026&lga=ZAR');
})->with(fn (): array => array_map(fn (string $old, string $new): array => [$old, $new], array_keys(OLD_URLS), OLD_URLS));

it('never names /login or /admin in a public page', function (string $path): void {
    $html = (string) $this->get($path)->assertOk()->getContent();

    expect($html)->not->toContain('/login')->not->toContain('/admin');
})->with(PUBLIC_PAGES);

// ---- Every response

it('says noindex, nofollow on every kind of response', function (): void {
    config(['edqa.kobo.webhook.token' => null, 'edqa.odk.webhook.secret' => null]);
    $user = User::factory()->create();

    $responses = [
        'public page' => $this->get('/'),
        'old URL' => $this->get('/dashboard/home'),
        'public 405' => $this->post('/'),
        'login' => $this->get('/login'),
        'admin as guest' => $this->get('/admin/sources'),
        'admin JSON as guest' => $this->getJson('/admin/api/sources'),
        'health' => $this->get('/up'),
        'webhook' => $this->postJson('/webhooks/kobo', []),
        'not found' => $this->get('/no-such-page'),
        'admin page' => $this->actingAs($user)->get('/admin/sources'),
    ];

    foreach ($responses as $what => $response) {
        expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow', "{$what} has no noindex header");
    }
});

// ---- Unchanged

it('has no registration routes', function (): void {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register', ['email' => 'x@example.test'])->assertNotFound();
});

it('keeps the source webhooks switched off (404) while no secret is configured', function (): void {
    config(['edqa.odk.webhook.secret' => null, 'edqa.kobo.webhook.token' => null]);

    $this->postJson('/webhooks/odk', [])->assertNotFound();
    $this->postJson('/webhooks/kobo', [])->assertNotFound();
});
