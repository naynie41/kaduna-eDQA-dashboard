<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Assessment\Events\AssessmentCorrected;
use App\Domain\Assessment\Events\AssessmentScored;
use App\Domain\Facility\Models\Lga;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Odk\OdkCentralSource;
use App\Domain\Ingestion\Odk\OdkConfig;
use App\Domain\Ingestion\Odk\SessionTokenCache;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Listeners\FlushRoundCache;
use App\Domain\Reporting\Listeners\RefreshAggregatesAfterScore;
use App\Domain\Reporting\RoundCache;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Events\RuleVersionPublished;
use App\Domain\Scoring\Listeners\RescoreAfterAssessmentCorrected;
use App\Domain\Scoring\Listeners\RescoreOpenRoundsAfterPublish;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Listeners\RescoreAfterQuarantineResolved;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        // ODK (ARCHITECTURE.md §5.2): settings read from config each time, so nothing stale is
        // held; the source behind the contract is ODK Central (another adapter if Q-01 says so).
        $this->app->bind(OdkConfig::class, fn (): OdkConfig => OdkConfig::fromConfig());
        $this->app->bind(SessionTokenCache::class, fn ($app): SessionTokenCache => new SessionTokenCache($app->make(OdkConfig::class)->refreshMinutes));
        $this->app->bind(SubmissionSource::class, OdkCentralSource::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // "Pull now": 6 a minute per administrator (SECURITY.md §8). Named, with its own key, so it
        // is not counted together with the API group's 120 a minute.
        RateLimiter::for('odk-pull', fn (Request $request): Limit => Limit::perMinute(6)->by('odk-pull:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Domain listeners live outside app/Listeners, so they are registered here.
        Event::listen(QuarantineResolved::class, RescoreAfterQuarantineResolved::class);
        Event::listen(RuleVersionPublished::class, RescoreOpenRoundsAfterPublish::class);
        Event::listen(AssessmentCorrected::class, RescoreAfterAssessmentCorrected::class);

        // Cached aggregates (RoundCache) follow the data they were computed from.
        Event::listen(AssessmentScored::class, [FlushRoundCache::class, 'onScored']);
        Event::listen(QuarantineResolved::class, [FlushRoundCache::class, 'onResolved']);
        Event::listen(RuleVersionPublished::class, [FlushRoundCache::class, 'onPublished']);
        Round::saved(fn () => app(RoundCache::class)->flushAll());
        Round::deleted(fn () => app(RoundCache::class)->flushAll());
        Lga::saved(fn () => ReportFilter::forgetLgaCodes());

        // round_aggregates follows scores (debounced); CloseRound refreshes it itself (the snapshot).
        Event::listen(AssessmentScored::class, RefreshAggregatesAfterScore::class);
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
