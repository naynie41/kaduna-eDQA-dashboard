<?php

declare(strict_types=1);

use App\Domain\Ingestion\Odk\OdkConfig;

// SECURITY.md §6: https only. The one exception is the local mock (docker/odk-mock), behind an
// explicit flag that is refused in every environment but local.

function odkSettings(array $overrides = []): array
{
    return ['url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'secret', 'project_id' => '1', 'form_id' => 'dqa', ...$overrides];
}

it('is configured with all five values and an https URL, in any environment', function (string $env): void {
    expect(OdkConfig::fromArray(odkSettings(), $env)->problems())->toBe([]);
})->with(['local', 'testing', 'staging', 'production']);

it('refuses plain http without the flag, even locally', function (): void {
    expect(OdkConfig::fromArray(odkSettings(['url' => 'http://odk-mock:8080']), 'local')->problems())->toBe(['ODK_CENTRAL_URL (https)']);
});

it('accepts plain http for the local mock with ODK_ALLOW_INSECURE_LOCAL in the local environment', function (): void {
    $config = OdkConfig::fromArray(odkSettings(['url' => 'http://odk-mock:8080', 'allow_insecure_local' => true]), 'local');

    expect($config->isConfigured())->toBeTrue()->and($config->url)->toBe('http://odk-mock:8080');
});

it('refuses the flag in any other environment, whatever the URL', function (string $env, string $url, array $problems): void {
    expect(OdkConfig::fromArray(odkSettings(['url' => $url, 'allow_insecure_local' => true]), $env)->problems())->toBe($problems);
})->with([
    'production, http' => ['production', 'http://odk-mock:8080', ['ODK_ALLOW_INSECURE_LOCAL (local only)', 'ODK_CENTRAL_URL (https)']],
    'production, https' => ['production', 'https://central.example.org', ['ODK_ALLOW_INSECURE_LOCAL (local only)']],
    'staging, http' => ['staging', 'http://odk-mock:8080', ['ODK_ALLOW_INSECURE_LOCAL (local only)', 'ODK_CENTRAL_URL (https)']],
    'testing, http' => ['testing', 'http://odk-mock:8080', ['ODK_ALLOW_INSECURE_LOCAL (local only)', 'ODK_CENTRAL_URL (https)']],
]);

it('never lets the flag open anything but http: other schemes are still refused', function (): void {
    expect(OdkConfig::fromArray(odkSettings(['url' => 'ftp://odk-mock', 'allow_insecure_local' => true]), 'local')->problems())->toBe(['ODK_CENTRAL_URL (https)']);
});

it('reads the flag only as an explicit true', function (mixed $value): void {
    expect(OdkConfig::fromArray(odkSettings(['url' => 'http://odk-mock:8080', 'allow_insecure_local' => $value]), 'local')->problems())->toBe(['ODK_CENTRAL_URL (https)']);
})->with(['the string' => ['true'], 'one' => [1], 'false' => [false], 'null' => [null]]);

it('never shows the password in a dump', function (): void {
    expect(print_r(OdkConfig::fromArray(odkSettings(['password' => 'Hunter2-secret'])), true))->not->toContain('Hunter2-secret')->toContain('[redacted]');
});
