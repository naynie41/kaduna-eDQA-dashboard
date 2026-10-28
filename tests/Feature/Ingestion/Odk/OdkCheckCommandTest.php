<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// edqa:odk:check: the first command when credentials arrive. No network.

const CHECK_URL = 'https://central.example.org';

beforeEach(function (): void {
    config(['edqa.sources.odk' => [...config('edqa.sources.odk'),
        'url' => CHECK_URL, 'email' => 'svc-edqa@kaduna.example', 'password' => 'S3cret-for-check', 'project_id' => '7', 'form_id' => 'dqa',
        'page_size' => 500, 'timeouts' => ['connect' => 10, 'total' => 60],
        'retry' => ['attempts' => 3, 'base_ms' => 1000, 'max_ms' => 30000], 'token_refresh_minutes' => 30,
    ]]);
    Http::preventStrayRequests();
    Sleep::fake();
});

function fakeCentral(): void
{
    Http::fake([
        CHECK_URL.'/v1/sessions' => Http::response(['token' => 'tok-check', 'expiresAt' => '2099-01-01T00:00:00.000Z']),
        CHECK_URL.'/v1/users/current' => Http::response(['displayName' => 'eDQA portal (read-only)']),
        CHECK_URL.'/v1/projects/7' => Http::response(['name' => 'Kaduna DQA']),
        CHECK_URL.'/v1/projects/7/forms/dqa' => Http::response(['name' => 'Data Quality Assessment', 'version' => '2026.1']),
        CHECK_URL.'/v1/projects/7/forms/dqa/versions' => Http::response([
            ['version' => '2026.1', 'publishedAt' => '2026-01-05T09:00:00.000Z'],
            ['version' => '2021.3', 'publishedAt' => '2021-08-01T09:00:00.000Z'],
        ]),
    ]);
}

/** @return array<string, mixed> */
function checkJson(): array
{
    Artisan::call('edqa:odk:check', ['--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('connects, names the account, project and form, and marks the versions with no field map', function (): void {
    fakeCentral();

    $this->artisan('edqa:odk:check')
        ->expectsOutputToContain('Connected as eDQA portal (read-only). Project "Kaduna DQA", form "Data Quality Assessment", current version 2026.1.')
        ->expectsOutputToContain('2026.1  published 2026-01-05T09:00:00+00:00  field map: yes')
        ->expectsOutputToContain('2021.3  published 2021-08-01T09:00:00+00:00  field map: NO')
        ->expectsOutputToContain('1 published version(s) have no field map')
        ->assertSuccessful();
});

it('gives the same as JSON', function (): void {
    fakeCentral();

    $json = checkJson();

    expect($json)->toMatchArray(['configured' => true, 'status' => 'ok', 'account' => 'eDQA portal (read-only)', 'project' => 'Kaduna DQA', 'current_version' => '2026.1'])
        ->and($json['versions'])->toBe([
            ['version' => '2026.1', 'published_at' => '2026-01-05T09:00:00+00:00', 'mapped' => true],
            ['version' => '2021.3', 'published_at' => '2021-08-01T09:00:00+00:00', 'mapped' => false],
        ]);
});

it('says plainly what is missing when not configured, sends nothing and fails', function (): void {
    config(['edqa.sources.odk.password' => null, 'edqa.sources.odk.form_id' => null]);
    Http::fake();

    $this->artisan('edqa:odk:check')
        ->expectsOutputToContain('Not configured. Set in .env: ODK_CENTRAL_PASSWORD, ODK_FORM_ID')
        ->assertFailed();
    expect(checkJson())->toMatchArray(['configured' => false, 'status' => 'not_configured', 'problems' => ['ODK_CENTRAL_PASSWORD', 'ODK_FORM_ID']]);
    Http::assertNothingSent();
});

it('reports each failure by kind, never printing the password or the token', function (int $status, string $kind): void {
    Http::fake([
        CHECK_URL.'/v1/sessions' => $status === 401 ? Http::response([], 401) : Http::response(['token' => 'tok-check', 'expiresAt' => '2099-01-01T00:00:00.000Z']),
        CHECK_URL.'/*' => Http::response([], $status),
    ]);

    $exit = Artisan::call('edqa:odk:check');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain("Failed ({$kind})")
        ->not->toContain('S3cret-for-check')->not->toContain('tok-check')
        ->and(checkJson()['status'])->toBe($kind);
})->with([
    'wrong password' => [401, 'auth_failed'],
    'no role' => [403, 'auth_failed'],
    'wrong project or form' => [404, 'unexpected_response'],
    'server down' => [503, 'unreachable'],
]);
