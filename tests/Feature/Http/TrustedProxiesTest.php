<?php

declare(strict_types=1);

// Forwarded headers (X-Forwarded-Host/Proto/Port/For) are honoured only from a private-network
// peer: a tunnel or proxy on the host or Docker network. Behind FastCGI, REMOTE_ADDR is the real
// client, so an internet client cannot choose the host the app builds its URLs from.

$forwarded = [
    'X-Forwarded-Host' => 'tunnel.example',
    'X-Forwarded-Proto' => 'https',
    'X-Forwarded-Port' => '443',
];

it('builds its URLs from the forwarded host when a private-network peer forwards the request', function (string $peer) use ($forwarded): void {
    $this->withServerVariables(['REMOTE_ADDR' => $peer])->withHeaders($forwarded)
        ->get('/admin')
        ->assertRedirect('https://tunnel.example/login');
})->with(['172.18.0.1', '10.0.0.5', '192.168.1.20', '127.0.0.1']);

it('ignores forwarded headers from a public address', function () use ($forwarded): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeaders($forwarded)
        ->get('/admin')
        ->assertRedirect(config('app.url').'/login');
});

it('ignores a forged forwarded host on a direct request', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'])
        ->get('/login')
        ->assertOk()
        ->assertDontSee('evil.example');
});
