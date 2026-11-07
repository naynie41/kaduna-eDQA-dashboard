<?php

declare(strict_types=1);

use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Round\Actions\CloseRound;
use App\Domain\Round\Models\Round;
use App\Models\User;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportingFixture;

// ARCHITECTURE.md §10: public pages served from cache. Aggregator results are cached per audience
// and filter (RoundCache, ReportFilter::cacheKey) until the next round close or rescore, which
// flush them. Anonymous responses tell the browser to keep them a short while; an administrator's
// never are kept.

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
    $this->f = ReportingFixture::build();
});

function publicSummary(): int
{
    return app(StateSummaryAggregator::class)->summary(new ReportFilter(audience: ReportAudience::Public))->facilitiesVisited;
}

it('serves the public figures from the cache, untouched by later score writes, until the next close', function (): void {
    expect(publicSummary())->toBe(1); // Q1 2026 (closed): F1 alone

    // A write that bypasses the app's events changes nothing the public sees: the result is cached.
    DB::table('assessments')->where('round_id', $this->f->q1)->update(['status' => 'withdrawn']);
    expect(publicSummary())->toBe(1);

    // Closing the open round flushes it: the public now sees Q2, the latest closed round.
    app(CloseRound::class)->execute(Round::query()->findOrFail($this->f->q2), User::factory()->create());
    expect(publicSummary())->toBe(7);
});

it('keeps the public and admin results apart in the cache', function (): void {
    expect(app(StateSummaryAggregator::class)->summary(new ReportFilter(audience: ReportAudience::Admin))->facilitiesVisited)->toBe(7)
        ->and(publicSummary())->toBe(1);
});

it('lets the browser keep an anonymous response a short while, never an administrator\'s', function (string $path): void {
    $anonymous = $this->get($path);
    expect($anonymous->headers->get('Cache-Control'))->toContain('max-age=60')->toContain('private')
        ->and($anonymous->baseResponse->getVary())->toContain('X-Inertia')->toContain('Cookie');

    $admin = $this->actingAs(User::factory()->create())->get($path);
    expect($admin->headers->get('Cache-Control'))->toContain('no-store')->not->toContain('max-age=60');
})->with(['/', '/facilities', '/api/public/aggregates', '/api/public/status']);

it('takes the short max-age from config', function (): void {
    expect(config('edqa.public.max_age'))->toBe(60);
    config(['edqa.public.max_age' => 15]);

    expect($this->get('/lgas')->headers->get('Cache-Control'))->toContain('max-age=15');
});

it('never caches an error or the admin area', function (): void {
    expect($this->get('/no-such-page')->headers->get('Cache-Control'))->not->toContain('max-age=60')
        ->and($this->get('/login')->headers->get('Cache-Control'))->not->toContain('max-age=60')
        ->and($this->get('/admin/sources')->headers->get('Cache-Control'))->not->toContain('max-age=60');
});
