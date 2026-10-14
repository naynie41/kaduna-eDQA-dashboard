<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Assessment\Events\AssessmentScored;
use App\Domain\Facility\Models\Lga;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Listeners\FlushRoundCache;
use App\Domain\Reporting\RoundCache;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Events\RuleVersionPublished;
use App\Domain\Scoring\Listeners\RescoreOpenRoundsAfterPublish;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Listeners\RescoreAfterQuarantineResolved;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

        // Domain listeners live outside app/Listeners, so they are registered here.
        Event::listen(QuarantineResolved::class, RescoreAfterQuarantineResolved::class);
        Event::listen(RuleVersionPublished::class, RescoreOpenRoundsAfterPublish::class);

        // Cached aggregates (RoundCache) follow the data they were computed from.
        Event::listen(AssessmentScored::class, [FlushRoundCache::class, 'onScored']);
        Event::listen(QuarantineResolved::class, [FlushRoundCache::class, 'onResolved']);
        Event::listen(RuleVersionPublished::class, [FlushRoundCache::class, 'onPublished']);
        Round::saved(fn () => app(RoundCache::class)->flushAll());
        Round::deleted(fn () => app(RoundCache::class)->flushAll());
        Lga::saved(fn () => ReportFilter::forgetLgaCodes());
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
