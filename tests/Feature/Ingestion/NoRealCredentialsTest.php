<?php

declare(strict_types=1);

// The developer's .env may hold real source credentials (Kobo credentials day). phpunit.xml blanks
// every one, so the suite never reaches a real server and never depends on what one .env holds. A
// test that needs a configured source sets it with config().

it('runs with no source credentials or URLs from the environment', function (string $key): void {
    expect((string) env($key))->toBe('');
})->with([
    'ODK_CENTRAL_URL', 'ODK_CENTRAL_EMAIL', 'ODK_CENTRAL_PASSWORD', 'ODK_PROJECT_ID', 'ODK_FORM_ID', 'ODK_WEBHOOK_SECRET',
    'KOBO_BASE_URL', 'KOBO_API_TOKEN', 'KOBO_ASSET_UID', 'KOBO_WEBHOOK_TOKEN',
]);

it('starts every test with neither source configured', function (): void {
    expect(config('edqa.sources.kobo.url'))->toBeNull()
        ->and(config('edqa.sources.kobo.token'))->toBeNull()
        ->and(config('edqa.sources.odk.url'))->toBeNull()
        ->and(config('edqa.sources.odk.password'))->toBeNull();
});
