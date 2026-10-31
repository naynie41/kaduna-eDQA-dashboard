<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\PullLock;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeKoboSource;
use Tests\Support\FakeSubmissionSource;
use Tests\Support\FixtureWorld;
use Tests\Support\Rows;

// Both sources through one pipeline (ARCHITECTURE.md §5.3, §5.8, D-60): each pulls under its own
// lock ("pull:{key}"), keeps its own cursor and run rows, and pings its own heartbeat; a
// submission copied from one source to the other is counted, not stored twice; a facility visited
// on both in one round is one assessment and one DUPLICATE_ASSESSMENT naming both sources.

beforeEach(function (): void {
    FixtureWorld::build();
    CurrentRuleVersion::forget();
    $chikun = (int) DB::table('lgas')->where('code', 'CHK')->value('id');
    $kujama = (int) DB::table('wards')->where('code', 'CHK-KUJ')->value('id');
    foreach (range(1, 12) as $n) {
        Rows::facility(['code' => sprintf('FX-5%03d', $n), 'name' => "Multi-source facility {$n}", 'lga_id' => $chikun, 'ward_id' => $kujama]);
    }
    configureSources(pageSize: 3);
    $this->odk = new FakeSubmissionSource;
    $this->kobo = new FakeKoboSource;
    useSource('odk', $this->odk);
    useSource('kobo', $this->kobo);
});

/** ODK entry $n: facility FX-5nnn, submitted $n minutes after 10:00 UTC on 15 April 2026. */
function msOdk(int $n, ?string $facility = null): array
{
    $entry = json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/odk/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
    $entry['__id'] = $entry['meta']['instanceID'] = "uuid:ms-odk-{$n}";
    $entry['__system']['submissionDate'] = CarbonImmutable::parse('2026-04-15T10:00:00.000Z')->addMinutes($n)->format('Y-m-d\TH:i:s.v\Z');
    $entry['__system']['deviceId'] = "collect:msodk{$n}";
    $entry['assessor_name'] = "ODK assessor {$n}";
    $entry['facility']['facility_code'] = $facility ?? sprintf('FX-5%03d', $n);

    return $entry;
}

/** Kobo row $n: facility FX-5nnn, _id 7000+n, submitted $n minutes after 11:00 UTC on 15 April 2026. */
function msKobo(int $n, ?string $facility = null, ?string $instance = null): array
{
    $row = json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/kobo/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
    $row['_id'] = 7000 + $n;
    $row['_uuid'] = "ms-kobo-{$n}";
    $row['meta/instanceID'] = $row['meta/rootUuid'] = $instance ?? "uuid:ms-kobo-{$n}";
    $row['_submission_time'] = CarbonImmutable::parse('2026-04-15T11:00:00Z')->addMinutes($n)->format('Y-m-d\TH:i:s');
    $row['deviceid'] = "collect:mskobo{$n}";
    $row['assessor_name'] = "Kobo assessor {$n}";
    $row['facility/facility_code'] = $facility ?? sprintf('FX-5%03d', $n);

    return $row;
}

function msPull(string $source): PullRun
{
    return app(PullSubmissions::class)->execute($source, PullTrigger::Scheduled)->refresh();
}

function msSync(string $source): SourceSync
{
    return SourceSync::query()->where('source_id', Rows::sourceId($source))->sole();
}

it('pulls each source into its own run, cursor and submissions', function (): void {
    $this->odk->add(msOdk(1), msOdk(2));
    $this->kobo->add(msKobo(3), msKobo(4), msKobo(5));

    $odk = msPull('odk');
    $kobo = msPull('kobo');

    expect([$odk->source_id, $odk->outcome, $odk->fetched, $odk->accepted])->toBe([Rows::sourceId('odk'), PullOutcome::Succeeded, 2, 2])
        ->and([$kobo->source_id, $kobo->outcome, $kobo->fetched, $kobo->accepted])->toBe([Rows::sourceId('kobo'), PullOutcome::Succeeded, 3, 3])
        ->and(msSync('odk')->form_ref)->toBe('7/dqa')
        ->and(msSync('kobo')->form_ref)->toBe('aKdDqaForm2026')
        ->and(msSync('kobo')->cursor_at?->utc()->format('Y-m-d\TH:i:s'))->toBe('2026-04-15T11:05:00')
        ->and(msSync('kobo')->submission_count)->toBe(3)
        ->and(Submission::query()->where('source_id', Rows::sourceId('kobo'))->pluck('external_id')->sort()->values()->all())->toBe(['7003', '7004', '7005'])
        ->and(Submission::query()->where('source_id', Rows::sourceId('odk'))->where('instance_id', 'like', 'uuid:ms-odk-%')->count())->toBe(2);
});

it('pulls both sources at the same time without either waiting for or touching the other', function (): void {
    $this->odk->add(msOdk(1), msOdk(2), msOdk(3), msOdk(4));
    $this->kobo->add(msKobo(5), msKobo(6), msKobo(7), msKobo(8));
    // While ODK's pull is between its pages (holding pull:odk), a whole Kobo pull runs.
    $kobo = null;
    $this->odk->failOn = function (int $call) use (&$kobo): ?Throwable {
        if ($call === 2) {
            $kobo = msPull('kobo');
        }

        return null;
    };

    $odk = msPull('odk');

    expect([$odk->outcome, $odk->fetched, $odk->accepted])->toBe([PullOutcome::Succeeded, 4, 4])
        ->and([$kobo?->outcome, $kobo?->fetched, $kobo?->accepted])->toBe([PullOutcome::Succeeded, 4, 4])
        ->and(PullRun::query()->count())->toBe(2)
        ->and(msSync('odk')->submission_count)->toBe(4)
        ->and(msSync('kobo')->submission_count)->toBe(4);
});

it('locks each source apart: a running pull of one never blocks the other, and never runs twice', function (): void {
    config(['edqa.pull.lock_wait_seconds' => 0]);
    $this->kobo->add(msKobo(1));
    $held = Cache::lock(PullLock::name('odk'), 900);
    expect($held->get())->toBeTrue();

    $odkJob = (new PullSubmissionsJob('odk'))->withFakeQueueInteractions();
    $odkJob->handle(app(PullLock::class), app(PullSubmissions::class));
    $koboJob = (new PullSubmissionsJob('kobo'))->withFakeQueueInteractions();
    $koboJob->handle(app(PullLock::class), app(PullSubmissions::class));

    $odkJob->assertReleased(60); // busy: back on the queue
    $koboJob->assertNotReleased();
    expect(PullLock::name('kobo'))->toBe('pull:kobo')
        ->and(PullRun::query()->where('source_id', Rows::sourceId('kobo'))->sole()->outcome)->toBe(PullOutcome::Succeeded)
        ->and(PullRun::query()->where('source_id', Rows::sourceId('odk'))->exists())->toBeFalse();
    $held->release();
});

it('pings only the heartbeat of the source that pulled', function (): void {
    config(['edqa.heartbeats.pull' => ['odk' => 'https://hc.example.org/ping/odk', 'kobo' => 'https://hc.example.org/ping/kobo']]);
    Http::fake();
    $this->kobo->add(msKobo(1));

    msPull('kobo');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://hc.example.org/ping/kobo');
});

it('resumes a source after a crash from its own last committed page, leaving the other alone', function (): void {
    $this->odk->add(msOdk(1), msOdk(2));
    $this->kobo->add(...array_map(fn (int $n): array => msKobo($n + 2), range(1, 7)));
    $this->kobo->failOn = fn (int $call): ?Throwable => $call === 2 ? new RuntimeException('Kobo went away') : null;

    expect(fn () => msPull('kobo'))->toThrow(RuntimeException::class, 'Kobo went away');
    $failed = PullRun::query()->where('source_id', Rows::sourceId('kobo'))->sole();
    $odk = msPull('odk');

    expect([$failed->outcome, $failed->fetched])->toBe([PullOutcome::Failed, 3]) // page one committed, cursor with it
        ->and(msSync('kobo')->cursor_at?->utc()->format('Y-m-d\TH:i:s'))->toBe('2026-04-15T11:05:00')
        ->and([$odk->outcome, $odk->accepted])->toBe([PullOutcome::Succeeded, 2]);

    $this->kobo->failOn = null;
    $resumed = msPull('kobo');

    // From the cursor (inclusive): the last row of page one again, dropped as a duplicate.
    expect([$resumed->outcome, $resumed->fetched, $resumed->duplicates, $resumed->accepted])->toBe([PullOutcome::Succeeded, 5, 1, 4])
        ->and(Submission::query()->where('source_id', Rows::sourceId('kobo'))->count())->toBe(7)
        ->and(msSync('kobo')->submission_count)->toBe(7);
});

it('counts a submission the other source already sent as a cross-source duplicate, stores it once and logs both sources', function (): void {
    Log::spy();
    $this->odk->add(msOdk(1));
    msPull('odk');
    // The same visit copied to Kobo: the same instance ID.
    $this->kobo->add(msKobo(2, facility: 'FX-5001', instance: 'uuid:ms-odk-1'), msKobo(3));

    $run = msPull('kobo');

    expect([$run->fetched, $run->duplicates, $run->cross_source_duplicates, $run->accepted, $run->quarantined])->toBe([2, 0, 1, 1, 0])
        ->and(Submission::query()->where('instance_id', 'uuid:ms-odk-1')->sole()->source_id)->toBe(Rows::sourceId('odk'))
        ->and(QuarantinedRecord::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Cross-source duplicate submission'
        && $context === ['instance_id' => 'uuid:ms-odk-1', 'source' => 'kobo', 'stored_from' => 'odk'])->once();

    // Pulled again from Kobo: only the row at the cursor is read again, Kobo's own, a plain duplicate.
    $again = msPull('kobo');
    expect([$again->duplicates, $again->cross_source_duplicates])->toBe([1, 0]);
});

it('keeps one assessment when both sources report a facility for one round: the later is DUPLICATE_ASSESSMENT naming both', function (): void {
    $this->odk->add(msOdk(1));
    $this->kobo->add(msKobo(2, facility: 'FX-5001'));

    msPull('odk');
    $run = msPull('kobo');

    $record = QuarantinedRecord::query()->where('instance_id', 'uuid:ms-kobo-2')->sole();
    expect([$run->accepted, $run->quarantined])->toBe([0, 1])
        ->and(array_column($record->failures, 'code'))->toBe(['DUPLICATE_ASSESSMENT'])
        ->and($record->failures[0]['detail'])->toBe('Q2 2026 already recorded for facility FX-5001 (instance uuid:ms-odk-1 from ODK Central; this one from KoboToolbox)')
        ->and($record->source_id)->toBe(Rows::sourceId('kobo'))
        ->and(Assessment::query()->whereHas('submission', fn ($q) => $q->where('instance_id', 'uuid:ms-odk-1'))->count())->toBe(1)
        ->and(Submission::query()->where('instance_id', 'uuid:ms-kobo-2')->sole()->status)->toBe(SubmissionStatus::Quarantined);
});

it('dry-runs a source without writing anything', function (): void {
    $this->kobo->add(msKobo(1), msKobo(2, facility: 'NOPE-2'), msKobo(3));
    $tables = ['submissions', 'pull_runs', 'source_syncs', 'quarantined_records', 'assessments', 'activity_log', 'submission_attachments'];
    $before = array_map(fn (string $t): int => DB::table($t)->count(), $tables);

    $this->artisan('edqa:pull', ['source' => 'kobo', '--dry-run' => true, '--limit' => 10])
        ->expectsOutputToContain('Fetched 3; 0 already stored; of the rest 2 would be accepted, 0 flagged and 1 quarantined.')
        ->assertSuccessful();
    $this->artisan('edqa:backfill', ['source' => 'kobo', '--dry-run' => true])->assertSuccessful();

    expect(array_map(fn (string $t): int => DB::table($t)->count(), $tables))->toBe($before);
});

it('pulls one source, every enabled source, and refuses a pull that names none or an unknown one', function (): void {
    $this->odk->add(msOdk(1));
    $this->kobo->add(msKobo(2));

    $this->artisan('edqa:pull', ['source' => 'kobo'])->expectsOutputToContain('KoboToolbox')->assertSuccessful();
    expect(PullRun::query()->pluck('source_id')->all())->toBe([Rows::sourceId('kobo')]);

    $this->artisan('edqa:pull', ['--all' => true])->assertSuccessful();
    expect(PullRun::query()->where('trigger_type', PullTrigger::Manual)->count())->toBe(3)
        ->and(PullRun::query()->where('source_id', Rows::sourceId('odk'))->sole()->accepted)->toBe(1);

    $this->artisan('edqa:pull')->expectsOutputToContain('Name a source (odk, kobo) or pass --all')->assertFailed();
    $this->artisan('edqa:pull', ['source' => 'aggregate'])->expectsOutputToContain('aggregate')->assertFailed();
    $this->artisan('edqa:pull', ['source' => 'kobo', '--all' => true])->expectsOutputToContain('Name a source (odk, kobo) or pass --all')->assertFailed();
});

it('skips a source that is not configured, and --all leaves it out', function (): void {
    config(['edqa.sources.kobo.token' => null]);
    $this->odk->add(msOdk(1));

    $this->artisan('edqa:pull', ['source' => 'kobo'])->expectsOutputToContain('KOBO_API_TOKEN')->assertFailed();
    $this->artisan('edqa:pull', ['--all' => true])->assertSuccessful();

    expect(PullRun::query()->where('source_id', Rows::sourceId('kobo'))->sole()->outcome)->toBe(PullOutcome::SkippedNotConfigured)
        ->and(PullRun::query()->where('source_id', Rows::sourceId('odk'))->sole()->outcome)->toBe(PullOutcome::Succeeded);
});
