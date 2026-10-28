<?php

declare(strict_types=1);

use App\Domain\Ingestion\Enums\SourceType;
use App\Domain\Ingestion\Exceptions\SourceRefused;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\Models\SubmissionSource;
use App\Domain\Ingestion\Odk\OdkCentralSource;
use App\Domain\Ingestion\SourceRegistry;
use Database\Factories\SubmissionSourceFactory;
use Spatie\Activitylog\Models\Activity;

// The field-collection sources (ARCHITECTURE.md §5.0): the registry, the sync of
// submission_sources with config, and the cursor one row per source.

function configureOdkSource(): void
{
    config(['edqa.sources.odk' => [...config('edqa.sources.odk'), 'url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'secret-for-tests', 'project_id' => '7', 'form_id' => 'dqa']]);
}

function configureKoboSource(): void
{
    config(['edqa.sources.kobo' => [...config('edqa.sources.kobo'), 'url' => 'https://kf.kobotoolbox.org', 'token' => 'token-for-tests', 'asset_uid' => 'aBcD1234']]);
}

// ---- The registry

it('knows each configured source by key, with its type, name, row and adapter', function (): void {
    $registry = app(SourceRegistry::class);

    expect($registry->keys())->toBe(['odk', 'kobo'])
        ->and($registry->type('odk'))->toBe(SourceType::OdkCentral)
        ->and($registry->type('kobo'))->toBe(SourceType::Kobo)
        ->and($registry->config('kobo')['name'])->toBe('KoboToolbox')
        ->and($registry->model('odk')->key)->toBe('odk')
        ->and($registry->id('odk'))->toBe(SubmissionSource::query()->where('key', 'odk')->value('id'))
        ->and($registry->adapter('odk'))->toBeInstanceOf(OdkCentralSource::class);
});

it('refuses a key that is not configured, a source not registered yet, and an adapter not built yet', function (): void {
    $registry = app(SourceRegistry::class);

    expect(fn () => $registry->config('aggregate'))->toThrow(fn (SourceRefused $e) => expect($e->errorCode)->toBe('unknown_source'))
        ->and(fn () => $registry->model('aggregate'))->toThrow(SourceRefused::class)
        ->and(fn () => $registry->model('kobo'))->toThrow(fn (SourceRefused $e) => expect($e->errorCode)->toBe('not_registered'))
        ->and(fn () => $registry->adapter('kobo'))->toThrow(fn (SourceRefused $e) => expect($e->errorCode)->toBe('no_adapter'));
});

it('lists as configured only the sources whose credentials are all set, never their values', function (): void {
    $registry = app(SourceRegistry::class);
    config(['edqa.sources.odk.url' => null, 'edqa.sources.kobo.url' => null]);

    expect($registry->configured())->toBe([])
        ->and($registry->problems('kobo'))->toBe(['KOBO_BASE_URL', 'KOBO_API_TOKEN', 'KOBO_ASSET_UID']);

    configureOdkSource();
    configureKoboSource();
    config(['edqa.sources.kobo.url' => 'http://kf.kobotoolbox.org']);
    expect($registry->configured())->toBe(['odk'])
        ->and($registry->problems('kobo'))->toBe(['KOBO_BASE_URL (https)'])
        ->and(implode(' ', $registry->problems('kobo')))->not->toContain('token-for-tests');

    configureKoboSource();
    expect($registry->configured())->toBe(['odk', 'kobo']);
});

it('lists as enabled only configured sources switched on in config and on their row', function (): void {
    configureOdkSource();
    configureKoboSource();
    $registry = app(SourceRegistry::class);

    expect($registry->enabled())->toBe(['odk']); // kobo has no row yet

    SubmissionSourceFactory::idFor('kobo');
    expect($registry->enabled())->toBe(['odk', 'kobo']);

    config(['edqa.sources.kobo.enabled' => false]);
    expect($registry->enabled())->toBe(['odk']);

    config(['edqa.sources.kobo.enabled' => true]);
    SubmissionSource::query()->where('key', 'odk')->update(['is_enabled' => false]);
    expect($registry->enabled())->toBe(['kobo']);
});

// ---- edqa:sources:sync

it('creates the missing rows from config, updates names and types, and never deletes', function (): void {
    SubmissionSource::query()->where('key', 'odk')->update(['name' => 'Old name']);
    $orphan = SubmissionSource::factory()->key('aggregate')->create(['name' => 'ODK Aggregate']);

    $this->artisan('edqa:sources:sync')
        ->expectsOutputToContain('Registered source kobo (KoboToolbox).')
        ->expectsOutputToContain('Updated source odk: name.')
        ->expectsOutputToContain('Source aggregate is registered but no longer configured.')
        ->assertSuccessful();

    expect(SubmissionSource::query()->orderBy('id')->pluck('name', 'key')->all())->toBe(['odk' => 'ODK Central', 'aggregate' => 'ODK Aggregate', 'kobo' => 'KoboToolbox'])
        ->and(SubmissionSource::query()->whereKey($orphan->id)->exists())->toBeTrue();

    $this->artisan('edqa:sources:sync')->expectsOutputToContain('Source odk is up to date.')->assertSuccessful();
    expect(SubmissionSource::query()->count())->toBe(3);
});

it('enables a new row as config says, then leaves is_enabled to the administrator', function (): void {
    config(['edqa.sources.kobo.enabled' => false]);
    $this->artisan('edqa:sources:sync')->assertSuccessful();
    expect(SubmissionSource::query()->where('key', 'kobo')->value('is_enabled'))->toBeFalse();

    SubmissionSource::query()->where('key', 'kobo')->update(['is_enabled' => true]);
    $this->artisan('edqa:sources:sync')->assertSuccessful();
    expect(SubmissionSource::query()->where('key', 'kobo')->value('is_enabled'))->toBeTrue();
});

it('audits what the sync changes', function (): void {
    SubmissionSource::query()->where('key', 'odk')->update(['name' => 'Old name']);

    $this->artisan('edqa:sources:sync')->assertSuccessful();

    $odk = SubmissionSource::query()->where('key', 'odk')->sole();
    $kobo = SubmissionSource::query()->where('key', 'kobo')->sole();
    $updated = Activity::query()->where('subject_type', $odk->getMorphClass())->where('subject_id', $odk->id)->where('event', 'updated')->sole();
    expect($updated->properties['old'])->toBe(['name' => 'Old name'])
        ->and($updated->properties['attributes'])->toBe(['name' => 'ODK Central'])
        ->and(Activity::query()->where('subject_id', $kobo->id)->where('subject_type', $kobo->getMorphClass())->where('event', 'created')->exists())->toBeTrue();
});

// ---- The cursor, one row per source

it('keeps one cursor per source, and starts it again when the configured form changes', function (): void {
    $odk = app(SourceRegistry::class)->id('odk');
    $sync = SourceSync::forForm($odk, '7/dqa');
    $sync->update(['cursor_at' => now(), 'backfill_checkpoint' => 500, 'submission_count' => 12, 'last_success_at' => now()]);

    expect(SourceSync::forForm($odk, '7/dqa')->backfill_checkpoint)->toBe(500)
        ->and(SourceSync::current($odk, '7/dqa')?->id)->toBe($sync->id)
        ->and(SourceSync::current($odk, '7/other'))->toBeNull();

    $moved = SourceSync::forForm($odk, '7/other');
    expect($moved->id)->toBe($sync->id)
        ->and($moved->form_ref)->toBe('7/other')
        ->and($moved->cursor_at)->toBeNull()
        ->and($moved->backfill_checkpoint)->toBe(0)
        ->and($moved->submission_count)->toBe(0)
        ->and($moved->formId())->toBe('other')
        ->and(SourceSync::query()->count())->toBe(1);
});

it('refuses a second cursor row for one source', function (): void {
    $odk = app(SourceRegistry::class)->id('odk');
    SourceSync::forForm($odk, '7/dqa');

    expectUniqueViolation(fn () => SourceSync::query()->create(['source_id' => $odk, 'form_ref' => '8/dqa']), 'source_syncs_source_id_unique');
});
