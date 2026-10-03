<?php

declare(strict_types=1);

use App\Models\User;

// SECURITY.md §8.

it('sends the security headers', function (string $path, bool $authenticated): void {
    if ($authenticated) {
        $this->actingAs(User::factory()->withTwoFactor()->create());
    }

    $response = $this->get($path);

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]{16,}'/")
        ->toContain("default-src 'self'")
        ->toContain("style-src 'self' 'unsafe-inline'")
        ->toContain("img-src 'self' data: blob:")
        ->toContain("font-src 'self'")
        ->toContain("connect-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        // No external hosts: fonts are self-hosted.
        ->not->toContain('https://');
})->with([
    'login page' => ['/login', false],
    'dashboard' => ['/', true],
]);

it('gives each response a fresh CSP nonce, used on the page scripts', function (): void {
    $first = $this->get('/login');
    $second = $this->get('/login');
    preg_match("/'nonce-([^']+)'/", (string) $first->headers->get('Content-Security-Policy'), $match);

    expect($match[1] ?? null)->not->toBeNull()
        ->and($second->headers->get('Content-Security-Policy'))->not->toContain($match[1]);
});

it('adds HSTS on HTTPS requests', function (): void {
    $this->get('https://localhost/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
