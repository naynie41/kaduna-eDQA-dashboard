<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Outside production: no lazy loading (N+1), no silently discarded or missing
        // attributes (CONVENTION.md §2.4).
        Model::shouldBeStrict(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // SECURITY.md §2, in every environment. uncompromised() asks the Have I Been Pwned API
        // (k-anonymity range query); tests skip it so they never depend on the network.
        Password::defaults(function (): Password {
            $rule = Password::min(12)->mixedCase()->numbers();

            return app()->environment('testing') ? $rule : $rule->uncompromised();
        });

        // HTTPS behind Caddy (which passes HTTPS=on over FastCGI); SECURITY.md §8.
        if (app()->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
