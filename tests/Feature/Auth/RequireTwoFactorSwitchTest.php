<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Security\TwoFactorSwitch;
use App\Support\Security\TwoFactorSwitchRefused;

// EDQA_REQUIRE_2FA (SECURITY.md §2): 2FA can be switched off for local development only. In any
// other environment a false value stops the app at boot with a clear error, like the
// *_ALLOW_INSECURE_LOCAL flags, and the middleware never skips the check there either.

/** Boots the app's provider as it would start in $environment with the flag at $required. */
function bootWith(string $environment, bool $required): void
{
    app()->detectEnvironment(fn (): string => $environment);
    config(['edqa.auth.require_two_factor' => $required]);
    (new AppServiceProvider(app()))->boot();
}

afterEach(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');
    config(['edqa.auth.require_two_factor' => true]);
});

it('boots with 2FA switched off in local only', function (): void {
    bootWith('local', false);

    expect(TwoFactorSwitch::required())->toBeFalse();
});

it('refuses to boot with 2FA switched off in :dataset, naming the flag and the environment', function (string $environment): void {
    expect(fn () => bootWith($environment, false))->toThrow(function (TwoFactorSwitchRefused $e) use ($environment): void {
        expect($e->getMessage())->toContain('EDQA_REQUIRE_2FA')->toContain("APP_ENV={$environment}")->toContain('local');
    });
})->with(['testing', 'staging', 'production']);

it('boots with 2FA required in every environment', function (string $environment): void {
    bootWith($environment, true);

    expect(TwoFactorSwitch::required())->toBeTrue();
})->with(['local', 'testing', 'staging', 'production']);

it('never skips the 2FA check outside local, even if the config was changed after boot', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);
    config(['edqa.auth.require_two_factor' => false]);
    $user = User::factory()->create(['two_factor_confirmed_at' => null]);

    expect(TwoFactorSwitch::required())->toBeTrue();
    $this->actingAs($user)->get('/dashboard/home')->assertRedirect();
})->with(['testing', 'staging', 'production']);
