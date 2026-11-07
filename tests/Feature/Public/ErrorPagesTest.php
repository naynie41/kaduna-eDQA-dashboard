<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// SECURITY.md §8: the public error pages (404, 429, 500) never show a stack trace, a file path, an
// ID or a source detail. Checked with APP_DEBUG=false, as staging and production run.

const SECRET = 'MARKER-SECRET uuid:7f3c2a10-0000-4000-8000-00000000abcd /var/www/html/app/Secret.php';

beforeEach(function (): void {
    config(['app.debug' => false]);
    RateLimiter::clear('public');
});

/** Nothing internal: no trace, no paths, no class names, no IDs, no secret. */
function expectPlainError(string $body): void
{
    expect($body)
        ->not->toContain('MARKER-SECRET')->not->toContain('uuid:')
        ->not->toContain('Stack trace')->not->toContain('#0 ')->not->toContain('/var/www')->not->toContain('vendor/')
        ->not->toContain('RuntimeException')->not->toContain('Illuminate\\')->not->toContain('APP_KEY')
        ->not->toContain('/login')->not->toContain('/admin');
}

it('answers a missing page with a plain 404', function (string $path): void {
    $response = $this->get($path);

    $response->assertNotFound();
    expectPlainError((string) $response->getContent());
    expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
})->with(['/no-such-page', '/api/public/facilities/999999', '/api/public/nothing-here']);

it('answers a server error with a plain 500, page or JSON', function (): void {
    Route::middleware(['web', 'public.readonly'])->get('/__public-boom', fn () => throw new RuntimeException(SECRET));

    $page = $this->get('/__public-boom');
    $page->assertStatus(500);
    expectPlainError((string) $page->getContent());

    $json = $this->getJson('/__public-boom');
    $json->assertStatus(500);
    expect($json->json())->toBe(['message' => 'Server Error']);
});

it('answers too many requests with the plain 429 page', function (): void {
    config(['edqa.public.per_minute' => 1]);
    $visitor = ['REMOTE_ADDR' => '203.0.113.90'];

    $this->withServerVariables($visitor)->get('/lgas')->assertOk();
    $response = $this->withServerVariables($visitor)->get('/lgas');

    $response->assertStatus(429);
    expectPlainError((string) $response->getContent());
});

it('would show the internals with APP_DEBUG on, so the checks above mean something', function (): void {
    config(['app.debug' => true]);
    Route::middleware(['web'])->get('/__debug-boom', fn () => throw new RuntimeException(SECRET));

    expect((string) $this->get('/__debug-boom')->getContent())->toContain('MARKER-SECRET');
});
