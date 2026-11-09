<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Assessment\Events\AssessmentCorrected;
use App\Domain\Assessment\Events\AssessmentScored;
use App\Domain\Assessment\Events\AssessmentStatusChanged;
use App\Domain\Facility\Models\Lga;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Events\DeletionReviewed;
use App\Domain\Ingestion\Events\SourceDeletionDetected;
use App\Domain\Ingestion\Kobo\KoboConfig;
use App\Domain\Ingestion\Odk\OdkConfig;
use App\Domain\Ingestion\Odk\SessionTokenCache;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Listeners\FlushRoundCache;
use App\Domain\Reporting\Listeners\RefreshAggregatesAfterScore;
use App\Domain\Reporting\Listeners\RefreshAggregatesAfterStatusChange;
use App\Domain\Reporting\RoundCache;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Events\RuleVersionPublished;
use App\Domain\Scoring\Listeners\RescoreAfterAssessmentCorrected;
use App\Domain\Scoring\Listeners\RescoreAfterStatusChange;
use App\Domain\Scoring\Listeners\RescoreOpenRoundsAfterPublish;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Events\SubmissionQuarantined;
use App\Domain\Validation\Listeners\FlushOpenQuarantineCount;
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
        // The sources (ARCHITECTURE.md §5.2): settings read from config each time, so nothing stale
        // is held. There is no default SubmissionSource: SourceRegistry::adapter() names the source.
        $this->app->bind(OdkConfig::class, fn (): OdkConfig => OdkConfig::fromConfig());
        $this->app->bind(KoboConfig::class, fn (): KoboConfig => KoboConfig::fromConfig());
        $this->app->bind(SessionTokenCache::class, fn ($app): SessionTokenCache => new SessionTokenCache($app->make(OdkConfig::class)->refreshMinutes));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // "Pull now": 6 a minute per administrator (SECURITY.md §8). Named, with its own key, so it
        // is not counted together with the API group's 120 a minute.
        // Pull, pull all and reconcile: 6 a minute per administrator, per source and action (the path).
        RateLimiter::for('source-pull', fn (Request $request): Limit => Limit::perMinute(6)->by('source-pull:'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.$request->path()));
        // The webhooks: 60 a minute per source and sending address.
        RateLimiter::for('webhook', fn (Request $request): Limit => Limit::perMinute(60)->by('webhook:'.$request->path().':'.$request->ip()));
        // The public dashboard: 120 a minute per IP (SECURITY.md §8), then a plain page back to Home.
        RateLimiter::for('public', fn (Request $request): Limit => Limit::perMinute((int) config('edqa.public.per_minute'))
            ->by('public:'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->view('errors.public-throttled', [], 429, $headers)));

        // Domain listeners live outside app/Listeners, so they are registered here.
        Event::listen(QuarantineResolved::class, RescoreAfterQuarantineResolved::class);
        // The Data issues badge: open records and unreviewed deletions at source (Phase 5).
        Event::listen([SubmissionQuarantined::class, QuarantineResolved::class, SourceDeletionDetected::class, DeletionReviewed::class], FlushOpenQuarantineCount::class);
        Event::listen(RuleVersionPublished::class, RescoreOpenRoundsAfterPublish::class);
        Event::listen(AssessmentCorrected::class, RescoreAfterAssessmentCorrected::class);

        // Cached aggregates (RoundCache) follow the data they were computed from.
        Event::listen(AssessmentScored::class, [FlushRoundCache::class, 'onScored']);
        Event::listen(QuarantineResolved::class, [FlushRoundCache::class, 'onResolved']);
        Event::listen(RuleVersionPublished::class, [FlushRoundCache::class, 'onPublished']);
        // A withdrawn or restored assessment (D-62): the round's cache, view and scores follow.
        Event::listen(AssessmentStatusChanged::class, [FlushRoundCache::class, 'onStatusChanged']);
        Event::listen(AssessmentStatusChanged::class, RefreshAggregatesAfterStatusChange::class);
        Event::listen(AssessmentStatusChanged::class, RescoreAfterStatusChange::class);
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
            $rule = Password::min(14)->mixedCase()->numbers()->symbols();

            return app()->environment('testing') ? $rule : $rule->uncompromised();
        });

        // HTTPS behind Caddy (which passes HTTPS=on over FastCGI); SECURITY.md §8.
        if (app()->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
