<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// edqa:source:check (Phase 2C): per source, configured, signed in, and its form versions. No network.

const SC_KOBO = 'https://kf.kobotoolbox.org';
const SC_TOKEN = 'kobo-check-token-77aa';

beforeEach(function (): void {
    config(['edqa.sources.odk.url' => null]); // ODK not configured unless a test says so
    config(['edqa.sources.kobo' => [...config('edqa.sources.kobo'), 'url' => SC_KOBO, 'token' => SC_TOKEN, 'asset_uid' => 'aBcD1234']]);
    config(['edqa_field_maps.sources' => [...config('edqa_field_maps.sources'), 'kobo' => ['vQ8xK2' => 'xls-2026.1']]]);
    Http::preventStrayRequests();
    Sleep::fake();
});

function fakeKobo(int $status = 200): void
{
    Http::fake([
        SC_KOBO.'/me/' => Http::response(['username' => 'kobo.reader'], $status),
        SC_KOBO.'/api/v2/assets/aBcD1234/' => Http::response(['uid' => 'aBcD1234', 'name' => 'Kaduna DQA', 'deployed_version_id' => 'vQ8xK2'], $status),
        SC_KOBO.'/api/v2/assets/aBcD1234/versions/*' => Http::response(['count' => 2, 'next' => null, 'results' => [
            ['uid' => 'vQ8xK2', 'date_deployed' => '2026-03-01T08:00:00Z'],
            ['uid' => 'vOld999', 'date_deployed' => '2025-06-01T08:00:00Z'],
        ]], $status),
    ]);
}

/** @return array<string, mixed> */
function sourceCheckJson(array $args): array
{
    Artisan::call('edqa:source:check', [...$args, '--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('checks one source: signed in, its versions, which have a field map', function (): void {
    fakeKobo();

    $this->artisan('edqa:source:check', ['source' => 'kobo'])
        ->expectsOutputToContain('KoboToolbox')
        ->expectsOutputToContain('Server: https://kf.kobotoolbox.org, asset aBcD1234')
        ->expectsOutputToContain('Connected as kobo.reader. Project "Kaduna DQA", form "Kaduna DQA", current version vQ8xK2.')
        ->expectsOutputToContain('vQ8xK2  published 2026-03-01T08:00:00+00:00  field map: yes')
        ->expectsOutputToContain('vOld999  published 2025-06-01T08:00:00+00:00  field map: NO')
        ->assertSuccessful();
});

it('gives one source as a JSON object, and every source with --all as a list', function (): void {
    fakeKobo();

    expect(sourceCheckJson(['source' => 'kobo']))->toMatchArray(['source' => 'kobo', 'type' => 'kobo', 'status' => 'ok', 'account' => 'kobo.reader', 'current_version' => 'vQ8xK2'])
        ->and(sourceCheckJson(['source' => 'kobo'])['versions'][1])->toBe(['version' => 'vOld999', 'published_at' => '2025-06-01T08:00:00+00:00', 'mapped' => false]);

    $all = sourceCheckJson(['--all' => true]);
    expect(array_column($all['sources'], 'source'))->toBe(['odk', 'kobo'])
        ->and(array_column($all['sources'], 'status'))->toBe(['not_configured', 'ok']);
});

it('checks every source without an argument, and fails while any is not ok', function (): void {
    fakeKobo();

    $this->artisan('edqa:source:check')
        ->expectsOutputToContain('ODK Central')
        ->expectsOutputToContain('Not configured. Set in .env: ODK_CENTRAL_URL')
        ->expectsOutputToContain('Connected as kobo.reader.')
        ->assertFailed();
});

it('names the auth failure and stops, never printing the token', function (): void {
    fakeKobo(401);

    $exit = Artisan::call('edqa:source:check', ['source' => 'kobo']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Failed (auth_failed)')->not->toContain(SC_TOKEN)
        ->and(sourceCheckJson(['source' => 'kobo'])['status'])->toBe('auth_failed');
    // One request each: a refused token is never retried.
    expect(Http::recorded()->count())->toBe(2);
});

it('says plainly what is missing, and sends nothing', function (): void {
    config(['edqa.sources.kobo.token' => null]);
    Http::fake();

    $this->artisan('edqa:source:check', ['source' => 'kobo'])
        ->expectsOutputToContain('Not configured. Set in .env: KOBO_API_TOKEN')
        ->assertFailed();
    Http::assertNothingSent();
});

it('refuses an unknown source and lists the configured ones', function (): void {
    $this->artisan('edqa:source:check', ['source' => 'aggregate'])
        ->expectsOutputToContain('aggregate is not a configured source. The sources are: odk, kobo.')
        ->assertFailed();
});

it('keeps edqa:odk:check as an alias of edqa:source:check odk for one release', function (): void {
    Http::fake();

    $this->artisan('edqa:odk:check')
        ->expectsOutputToContain('Not configured. Set in .env: ODK_CENTRAL_URL')
        ->expectsOutputToContain('edqa:odk:check is now edqa:source:check odk')
        ->assertFailed();

    Artisan::call('edqa:odk:check', ['--json' => true]);
    expect(json_decode(Artisan::output(), true))->toMatchArray(['source' => 'odk', 'status' => 'not_configured']);
});
