<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\E2eAdminSeeder;

// The Playwright suite signs in as a fixed administrator with a known password (no MFA, D-35).
// Local (and the test suite) only: a known password must never reach staging or production.

it('creates one active administrator with the known password, unlocked', function (): void {
    $this->seed(E2eAdminSeeder::class);
    $this->seed(E2eAdminSeeder::class); // idempotent

    $user = User::query()->where('email', E2eAdminSeeder::EMAIL)->sole();
    expect($user->is_active)->toBeTrue()
        ->and(Hash::check(E2eAdminSeeder::PASSWORD, $user->password))->toBeTrue()
        ->and($user->failed_login_count)->toBe(0);
});

it('lifts a lockout an earlier e2e run left behind', function (): void {
    $this->seed(E2eAdminSeeder::class);
    User::query()->where('email', E2eAdminSeeder::EMAIL)->update(['failed_login_count' => 20, 'locked_out_at' => now()]);

    $this->seed(E2eAdminSeeder::class);

    $user = User::query()->where('email', E2eAdminSeeder::EMAIL)->sole();
    expect($user->failed_login_count)->toBe(0)
        ->and($user->locked_out_at)->toBeNull();
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
        ->not->toContain('TOTP');
});

it('is not part of the normal seed', function (): void {
    expect((string) file_get_contents(database_path('seeders/DatabaseSeeder.php')))->not->toContain('E2eAdminSeeder');
});
