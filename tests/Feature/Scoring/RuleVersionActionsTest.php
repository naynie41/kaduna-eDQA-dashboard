<?php

declare(strict_types=1);

use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Actions\CreateDraftRuleVersion;
use App\Domain\Scoring\Actions\PublishRuleVersion;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\Events\RuleVersionPublished;
use App\Domain\Scoring\Exceptions\InvalidRuleConfig;
use App\Domain\Scoring\Exceptions\RuleVersionRefused;
use App\Domain\Scoring\Listeners\RescoreOpenRoundsAfterPublish;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;
use App\Models\User;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    $this->v1 = ScoringRuleVersion::query()->where('version', 1)->sole();
    $this->admin = User::factory()->withTwoFactor()->create();
});

const PUBLISH_REASON = 'Raise the Strong band after the 2026 review';

// ---- Drafts

it('creates a draft from a version: next number, unpublished, changes applied', function (): void {
    $draft = app(CreateDraftRuleVersion::class)->execute($this->v1, ['bands' => ['strong' => 95, 'acceptable' => 85, 'review' => 75]], $this->admin);

    expect($draft->version)->toBe(2)
        ->and($draft->published_at)->toBeNull()
        ->and($draft->created_by)->toBe($this->admin->id)
        ->and($draft->config['bands'])->toEqual(['strong' => 95, 'acceptable' => 85, 'review' => 75])
        ->and($draft->config['choice_map'])->toEqual($this->v1->config['choice_map']);

    $entry = Activity::query()->forSubject($draft)->sole();
    expect($entry->event)->toBe('drafted')
        ->and($entry->causer?->is($this->admin))->toBeTrue()
        ->and($entry->properties['from_version'])->toBe(1);
});

it('allows only one draft at a time', function (): void {
    app(CreateDraftRuleVersion::class)->execute($this->v1, [], $this->admin);

    expect(fn () => app(CreateDraftRuleVersion::class)->execute($this->v1, [], $this->admin))
        ->toThrow(RuleVersionRefused::class, __('scoring.errors.draft_exists'));
    expect(ScoringRuleVersion::query()->whereNull('published_at')->count())->toBe(1);
});

it('refuses a draft whose config the calculator could not apply', function (array $changes): void {
    expect(fn () => app(CreateDraftRuleVersion::class)->execute($this->v1, $changes, $this->admin))
        ->toThrow(InvalidRuleConfig::class);
    expect(ScoringRuleVersion::query()->count())->toBe(1);
})->with([
    'bands not descending' => [['bands' => ['strong' => 80, 'acceptable' => 85, 'review' => 70]]],
    'band above 100' => [['bands' => ['strong' => 120, 'acceptable' => 85, 'review' => 70]]],
    'zero weight' => [['item_weights' => ['availability.summary_form' => 0]]],
    'choice map missing N/A' => [['choice_map' => ['yes' => 'pass', 'no' => 'fail']]],
    'unknown N/A policy' => [['na_policy' => 'count_as_fail']],
]);

it('refuses to change anything but the four config keys', function (): void {
    expect(fn () => app(CreateDraftRuleVersion::class)->execute($this->v1, ['weighting' => 'facility_count'], $this->admin))
        ->toThrow(RuleVersionRefused::class, __('scoring.errors.unknown_config_key', ['key' => 'weighting']));
});

// ---- Publishing

it('publishes a draft with a reason, audited, and makes it current', function (): void {
    Event::fake([RuleVersionPublished::class]);
    $draft = app(CreateDraftRuleVersion::class)->execute($this->v1, [], $this->admin);
    expect(app(CurrentRuleVersion::class)->id())->toBe($this->v1->id);

    app(PublishRuleVersion::class)->execute($draft, $this->admin, PUBLISH_REASON);

    $draft->refresh();
    expect($draft->published_at)->not->toBeNull()
        ->and($draft->published_reason)->toBe(PUBLISH_REASON)
        ->and(app(CurrentRuleVersion::class)->id())->toBe($draft->id);

    $entry = Activity::query()->forSubject($draft)->where('event', 'published')->sole();
    expect($entry->causer?->is($this->admin))->toBeTrue()
        ->and($entry->properties['reason'])->toBe(PUBLISH_REASON)
        ->and($entry->properties['old'])->toMatchArray(['published_at' => null])
        ->and($entry->properties['attributes']['published_at'])->not->toBeNull()
        ->and(Activity::query()->forSubject($draft)->where('event', 'updated')->count())->toBe(0);

    Event::assertDispatched(RuleVersionPublished::class, fn (RuleVersionPublished $e): bool => $e->ruleVersionId === $draft->id);
});

it('refuses to publish without a reason of at least 10 characters', function (string $reason): void {
    $draft = app(CreateDraftRuleVersion::class)->execute($this->v1, [], $this->admin);

    expect(fn () => app(PublishRuleVersion::class)->execute($draft, $this->admin, $reason))
        ->toThrow(RuleVersionRefused::class, __('scoring.errors.reason_too_short'));
    expect($draft->refresh()->published_at)->toBeNull();
})->with(['', 'too short']);

it('refuses to publish a version that is already published', function (): void {
    expect(fn () => app(PublishRuleVersion::class)->execute($this->v1, $this->admin, PUBLISH_REASON))
        ->toThrow(RuleVersionRefused::class, __('scoring.errors.not_a_draft'));
});

// ---- Resolvers

it('resolves the current version once, then from cache until a publish', function (): void {
    $current = app(CurrentRuleVersion::class);
    $current->id();

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect($current->id())->toBe($this->v1->id)
        ->and(app(CurrentRuleVersion::class)->config()?->bands['strong'])->toBe(90.0)
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

it('gives an open round the current version and a closed round its pinned one, even after a publish', function (): void {
    $open = Round::factory()->create();
    $closed = Round::factory()->closed()->create();
    expect($closed->rule_version_id)->toBe($this->v1->id);

    $draft = app(CreateDraftRuleVersion::class)->execute($this->v1, ['bands' => ['strong' => 95, 'acceptable' => 85, 'review' => 75]], $this->admin);
    app(PublishRuleVersion::class)->execute($draft, $this->admin, PUBLISH_REASON);

    $resolver = app(RuleVersionForRound::class);
    expect($resolver->id($open->refresh()))->toBe($draft->id)
        ->and($resolver->id($closed->refresh()))->toBe($this->v1->id)
        ->and($closed->rule_version_id)->toBe($this->v1->id)
        ->and($resolver->config($closed)->bands['strong'])->toBe(90.0)
        ->and($resolver->config($open)->bands['strong'])->toBe(95.0);
});

it('asks for a rescore of open rounds only after a publish', function (): void {
    $open = Round::factory()->create();
    Round::factory()->closed()->create();
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->once()->withArgs(fn (string $message, array $context): bool => $message === 'rule_version.published.rescore_pending'
        && $context['round_ids'] === [$open->id]);
    Log::shouldReceive('channel')->with('edqa')->andReturn($logger);

    app(RescoreOpenRoundsAfterPublish::class)->handle(new RuleVersionPublished($this->v1->id));
});

it('wires the rescore listener to the publish event', function (): void {
    Event::fake([RuleVersionPublished::class]);

    Event::assertListening(RuleVersionPublished::class, RescoreOpenRoundsAfterPublish::class);
});
