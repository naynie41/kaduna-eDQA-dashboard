<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

// SECURITY.md §8 and D-82: the public dashboard is rate limited per IP with a plain 429 page,
// says noindex everywhere (unless edqa.public.indexable), and robots.txt keeps crawlers out of
// /admin and /login.

beforeEach(fn () => RateLimiter::clear('public'));

it('allows 120 public requests a minute per IP by default', function (): void {
    expect(config('edqa.public.per_minute'))->toBe(120);
});

it('answers the next request over the limit with a plain 429 page that links to Home', function (): void {
    config(['edqa.public.per_minute' => 2]);
    $visitor = ['REMOTE_ADDR' => '203.0.113.40'];

    $this->withServerVariables($visitor)->get('/lgas')->assertOk();
    $this->withServerVariables($visitor)->get('/monitor')->assertOk();
    $response = $this->withServerVariables($visitor)->get('/plan');

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertSee(__('errors.too_many.title'))
        ->assertSee('href="/"', false)
        ->assertSee(__('errors.too_many.home'));
    expect($response->getContent())->not->toContain('/login')->not->toContain('/admin')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
});

it('counts each visitor separately', function (): void {
    config(['edqa.public.per_minute' => 1]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])->get('/lgas')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])->get('/lgas')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])->get('/lgas')->assertStatus(429);
});

it('puts a robots meta tag in every page', function (string $path): void {
    $this->get($path)->assertSee('<meta name="robots" content="noindex, nofollow">', false);
})->with(['/', '/login']);

it('lets the public pages be indexed only when edqa.public.indexable is on, never /admin or /login', function (): void {
    expect(config('edqa.public.indexable'))->toBeFalse();
    config(['edqa.public.indexable' => true]);

    $public = $this->get('/');
    expect($public->headers->has('X-Robots-Tag'))->toBeFalse()
        ->and($public->getContent())->not->toContain('name="robots"');

    expect($this->get('/login')->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($this->get('/admin/sources')->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
});

it('keeps crawlers out of /admin and /login in robots.txt', function (): void {
    $robots = (string) file_get_contents(public_path('robots.txt'));

    expect($robots)->toContain('User-agent: *')
        ->toContain("Disallow: /admin\n")
        ->toContain("Disallow: /login\n");
});

it('signs an administrator out after 12 hours but still shows them the public page', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession([EnforceAbsoluteSessionLifetime::SESSION_KEY => now()->subHours(13)->getTimestamp()])
        ->get('/')->assertOk();

    $this->assertGuest();
});
