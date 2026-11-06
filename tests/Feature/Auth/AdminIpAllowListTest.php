<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Config\EnvList;

// SECURITY.md §2: the optional admin IP allow-list (EDQA_ADMIN_ALLOWED_IPS). When set, sign-in,
// password reset and every signed-in page answer 404 to any other address. Empty = off.

const ALLOWED = ['10.1.0.0/16', '203.0.113.9'];

/** @return list<string> */
function guardedPaths(): array
{
    return ['/login', '/forgot-password', '/reset-password/some-token', '/admin/data-issues', '/admin/sources', '/admin/settings/security'];
}

it('reads a comma-separated CIDR list, ignoring spaces and empty entries', function (): void {
    expect(EnvList::csv(' 10.1.0.0/16, 203.0.113.9 ,,'))->toBe(['10.1.0.0/16', '203.0.113.9'])
        ->and(EnvList::csv(''))->toBe([])
        ->and(EnvList::csv(null))->toBe([]);
});

it('is off by default: any address reaches the login page', function (): void {
    expect(config('edqa.auth.admin_allowed_ips'))->toBe([]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->get('/login')->assertOk();
});

it('answers 404 to an address outside the list, signed in or not', function (string $path): void {
    config(['edqa.auth.admin_allowed_ips' => ALLOWED]);
    $outside = ['REMOTE_ADDR' => '198.51.100.1'];

    $this->withServerVariables($outside)->get($path)->assertNotFound();
    $this->withServerVariables($outside)->actingAs(User::factory()->create())->get($path)->assertNotFound();
})->with(guardedPaths());

it('refuses the sign-in and reset forms themselves to an address outside the list', function (): void {
    config(['edqa.auth.admin_allowed_ips' => ALLOWED]);
    $user = User::factory()->create();
    $outside = ['REMOTE_ADDR' => '198.51.100.1'];

    $this->withServerVariables($outside)->post('/login', ['email' => $user->email, 'password' => 'password'])->assertNotFound();
    $this->assertGuest();
    $this->withServerVariables($outside)->post('/forgot-password', ['email' => $user->email])->assertNotFound();
    $this->withServerVariables($outside)->post('/reset-password', ['email' => $user->email])->assertNotFound();
});

it('lets an address inside the list through, by range or exact address', function (string $ip): void {
    config(['edqa.auth.admin_allowed_ips' => ALLOWED]);

    $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/login')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => $ip])->actingAs(User::factory()->create())->get('/admin/sources')->assertOk();
})->with(['10.1.2.3', '203.0.113.9']);

it('leaves the public dashboard open to every address (D-76)', function (string $path): void {
    config(['edqa.auth.admin_allowed_ips' => ALLOWED]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->get($path)->assertOk();
})->with(['/', '/facilities', '/lgas']);

it('leaves the health check and the webhooks to their own rules', function (): void {
    config(['edqa.auth.admin_allowed_ips' => ALLOWED, 'edqa.kobo.webhook.token' => 'kobo-webhook-token-0123456789abcdefghij']);
    $outside = ['REMOTE_ADDR' => '198.51.100.1'];

    $this->withServerVariables($outside)->get('/up')->assertOk();
    // Refused by its token check (401), not hidden by the allow-list.
    $this->withServerVariables($outside)->postJson('/webhooks/kobo', [])->assertUnauthorized();
});

it('uses the forwarded client address from a trusted proxy, and only from one', function (): void {
    config(['edqa.auth.admin_allowed_ips' => ALLOWED]);

    // Caddy or a tunnel on the private network forwards the real client.
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])->withHeader('X-Forwarded-For', '203.0.113.9')
        ->get('/login')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])->withHeader('X-Forwarded-For', '198.51.100.1')
        ->get('/login')->assertNotFound();

    // A public client cannot claim an allowed address.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeader('X-Forwarded-For', '203.0.113.9')
        ->get('/login')->assertNotFound();
});
