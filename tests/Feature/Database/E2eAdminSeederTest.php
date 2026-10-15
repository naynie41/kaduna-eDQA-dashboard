<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\E2eAdminSeeder;
use PragmaRX\Google2FA\Google2FA;

// The Playwright suite signs in as a fixed administrator with a fixed TOTP secret. Local (and the
// test suite) only: a known password and secret must never reach staging or production.

it('creates one confirmed-2FA administrator whose code the fixed secret produces', function (): void {
    $this->seed(E2eAdminSeeder::class);
    $this->seed(E2eAdminSeeder::class); // idempotent

    $user = User::query()->where('email', E2eAdminSeeder::EMAIL)->sole();
    expect($user->is_active)->toBeTrue()
        ->and($user->two_factor_confirmed_at)->not->toBeNull()
        ->and(decrypt((string) $user->two_factor_secret))->toBe(E2eAdminSeeder::TOTP_SECRET)
        ->and(Hash::check(E2eAdminSeeder::PASSWORD, $user->password))->toBeTrue()
        ->and(app(Google2FA::class)->verifyKey(E2eAdminSeeder::TOTP_SECRET, app(Google2FA::class)->getCurrentOtp(E2eAdminSeeder::TOTP_SECRET)))->toBeTrue();
});

it('refuses to run anywhere but local and the test suite', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    expect(fn () => (new E2eAdminSeeder)->run())->toThrow(RuntimeException::class, 'E2eAdminSeeder');
    expect(User::query()->where('email', E2eAdminSeeder::EMAIL)->exists())->toBeFalse();
})->with(['staging', 'production']);

it('agrees with the credentials the Playwright login uses', function (): void {
    $credentials = (string) file_get_contents(base_path('tests/e2e/support/credentials.ts'));

    expect($credentials)->toContain("'".E2eAdminSeeder::EMAIL."'")
        ->toContain("'".E2eAdminSeeder::PASSWORD."'")
        ->toContain("'".E2eAdminSeeder::TOTP_SECRET."'");
});

it('is not part of the normal seed', function (): void {
    expect((string) file_get_contents(database_path('seeders/DatabaseSeeder.php')))->not->toContain('E2eAdminSeeder');
});
