<?php

declare(strict_types=1);

use App\Domain\Ingestion\Actions\BackfillSubmissions;
use App\Domain\Ingestion\BackfillLock;
use App\Domain\Ingestion\DTOs\BackfillReport;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\BackfillRefused;
use App\Domain\Ingestion\Jobs\BackfillSubmissionsJob;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SourceSync;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Queries\BackfillReportQuery;
use App\Domain\Scoring\CurrentRuleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakeKoboSource;
use Tests\Support\FakeSubmissionSource;
use Tests\Support\FixtureWorld;
use Tests\Support\Rows;

// The backfill per source (ARCHITECTURE.md §5.6): the same page path from the start of one
// source's form, one source at a time; and the report, broken down by source first, with the
// duplicates the client must decide between when both sources hold the same quarters (Q-15).

beforeEach(function (): void {
    FixtureWorld::build();
    CurrentRuleVersion::forget();
    $chikun = (int) DB::table('lgas')->where('code', 'CHK')->value('id');
    $kujama = (int) DB::table('wards')->where('code', 'CHK-KUJ')->value('id');
    foreach (range(1, 8) as $n) {
        Rows::facility(['code' => sprintf('FX-7%03d', $n), 'name' => "Two-source facility {$n}", 'lga_id' => $chikun, 'ward_id' => $kujama]);
    }
    configureSources(pageSize: 2);
    Sleep::fake();
    Storage::fake('attachments');
    Storage::fake('exports');
    $this->odk = new FakeSubmissionSource;
    $this->kobo = new FakeKoboSource;
    useSource('odk', $this->odk);
    useSource('kobo', $this->kobo);
});

function mbOdk(int $n, ?string $facility = null): array
{
    $entry = json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/odk/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
    $entry['__id'] = $entry['meta']['instanceID'] = "uuid:mb-odk-{$n}";
    $entry['__system']['submissionDate'] = CarbonImmutable::parse('2026-04-20T10:00:00.000Z')->addMinutes($n)->format('Y-m-d\TH:i:s.v\Z');
    $entry['__system']['deviceId'] = "collect:mbodk{$n}";
    $entry['assessor_name'] = "ODK assessor {$n}";
    $entry['facility']['facility_code'] = $facility ?? sprintf('FX-7%03d', $n);

    return $entry;
}

function mbKobo(int $n, ?string $facility = null, ?string $instance = null): array
{
    $row = json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/kobo/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
    $row['_id'] = 9000 + $n;
    $row['_uuid'] = "mb-kobo-{$n}";
    $row['meta/instanceID'] = $instance ?? "uuid:mb-kobo-{$n}";
    $row['_submission_time'] = CarbonImmutable::parse('2026-04-20T11:00:00Z')->addMinutes($n)->format('Y-m-d\TH:i:s');
    $row['deviceid'] = "collect:mbkobo{$n}";
    $row['assessor_name'] = "Kobo assessor {$n}";
    $row['facility/facility_code'] = $facility ?? sprintf('FX-7%03d', $n);

    return $row;
}

it('backfills one source from the start of its form, checkpointed, into its own run', function (): void {
    $this->kobo->add(mbKobo(1), mbKobo(2), mbKobo(3));
    $this->odk->add(mbOdk(4));

    $run = app(BackfillSubmissions::class)->execute('kobo')->run->refresh();

    $sync = SourceSync::query()->where('source_id', Rows::sourceId('kobo'))->sole();
    expect([$run->source_id, $run->trigger_type, $run->outcome, $run->fetched, $run->accepted])->toBe([Rows::sourceId('kobo'), PullTrigger::Backfill, PullOutcome::Succeeded, 3, 3])
        ->and($sync->backfill_checkpoint)->toBe(3)
        ->and($sync->cursor_at?->utc()->format('Y-m-d\TH:i:s'))->toBe('2026-04-20T11:03:00')
        ->and(collect($this->kobo->calls)->pluck('since')->unique()->all())->toBe([null]) // from the start
        ->and($this->odk->calls)->toBe([])
        ->and(Submission::query()->where('instance_id', 'like', 'uuid:mb-%')->where('source_id', Rows::sourceId('odk'))->exists())->toBeFalse();
});

it('refuses to backfill a second source while one is running', function (): void {
    $this->kobo->add(mbKobo(1));
    $held = Cache::lock(BackfillLock::NAME, 60);
    expect($held->get())->toBeTrue();

    $this->artisan('edqa:backfill', ['source' => 'kobo'])->expectsOutputToContain('A backfill is already running.')->assertFailed();
    $held->release();

    // A chunked backfill of ODK between two of its jobs holds no lock, but its run is still open.
    PullRun::query()->create(['source_id' => Rows::sourceId('odk'), 'trigger_type' => PullTrigger::Backfill, 'started_at' => now()->subMinutes(10)]);
    expect(fn () => app(BackfillSubmissions::class)->execute('kobo'))
        ->toThrow(BackfillRefused::class, 'A backfill of ODK Central is still running. Backfill one source at a time: let it finish first.')
        ->and(PullRun::query()->where('source_id', Rows::sourceId('kobo'))->exists())->toBeFalse();

    // One left open by a crash long ago does not block for ever.
    PullRun::query()->where('source_id', Rows::sourceId('odk'))->update(['started_at' => now()->subDays(2)]);
    expect(app(BackfillSubmissions::class)->execute('kobo')->run->refresh()->accepted)->toBe(1);
});

it('carries on its own source chunk after chunk on the queue', function (): void {
    Queue::fake([BackfillSubmissionsJob::class]);
    config(['edqa.backfill.job_seconds' => 0]); // one page a job
    $this->kobo->add(mbKobo(1), mbKobo(2), mbKobo(3));

    (new BackfillSubmissionsJob('kobo'))->handle(app(BackfillSubmissions::class), app(BackfillLock::class));

    Queue::assertPushed(BackfillSubmissionsJob::class, fn (BackfillSubmissionsJob $job): bool => $job->source === 'kobo' && $job->runId !== null && $job->queue === 'low');
});

it('names the source on the command, and refuses one it does not know', function (): void {
    $this->kobo->add(mbKobo(1));

    $this->artisan('edqa:backfill', ['source' => 'kobo'])->expectsOutputToContain('Backfill finished')->assertSuccessful();
    $this->artisan('edqa:backfill', ['source' => 'aggregate'])->expectsOutputToContain('aggregate')->assertFailed();
    $this->artisan('edqa:backfill', ['source' => 'kobo', '--survey' => true])->expectsOutputToContain('1 submission')->assertSuccessful();
});

it('breaks the report down by source, then round, with the duplicates between the sources', function (): void {
    // ODK: two visits. Kobo: a copy of ODK's first (the same instance), a second visit to ODK's
    // second facility (DUPLICATE_ASSESSMENT), and one of its own.
    $this->odk->add(mbOdk(1), mbOdk(2));
    $this->kobo->add(mbKobo(3, instance: 'uuid:mb-odk-1', facility: 'FX-7001'), mbKobo(4, facility: 'FX-7002'), mbKobo(5));
    $before = app(BackfillReportQuery::class)->build(); // FixtureWorld's own ODK submissions
    app(BackfillSubmissions::class)->execute('odk');
    app(BackfillSubmissions::class)->execute('kobo');
    app(BackfillSubmissions::class)->execute('kobo', fromPage: 0); // run again from the start: the copy is counted again

    $report = app(BackfillReportQuery::class)->build();
    $q2 = (int) DB::table('rounds')->where('year', 2026)->where('quarter', 2)->value('id');
    $row = fn (BackfillReport $report, ?string $source, ?int $round, bool $total = false): array => collect($report->rounds)
        ->first(fn (array $r): bool => $r['source'] === $source && $r['total'] === $total && ($total || $r['round_id'] === $round)) ?? ['pulled' => 0, 'accepted' => 0, 'quarantined' => 0];
    $grew = fn (?string $source, ?int $round, bool $total = false): array => array_map(
        fn (string $m): int => $row($report, $source, $round, $total)[$m] - $row($before, $source, $round, $total)[$m],
        ['pulled' => 'pulled', 'accepted' => 'accepted', 'quarantined' => 'quarantined'],
    );

    expect($grew('odk', $q2))->toBe(['pulled' => 2, 'accepted' => 2, 'quarantined' => 0])
        ->and($row($report, 'kobo', $q2))->toMatchArray(['pulled' => 2, 'accepted' => 1, 'quarantined' => 1])
        ->and($row($report, 'kobo', null, true))->toMatchArray(['pulled' => 2])
        ->and($grew(null, null, true))->toBe(['pulled' => 4, 'accepted' => 3, 'quarantined' => 1])
        ->and(collect($report->byVersion)->firstWhere('code', 'DUPLICATE_ASSESSMENT'))->toMatchArray(['source' => 'kobo', 'version' => 'vKd26Q1aBcDeFgHiJkLmNo', 'records' => 1])
        ->and(collect($report->byYear)->firstWhere('code', 'DUPLICATE_ASSESSMENT'))->toMatchArray(['source' => 'kobo', 'year' => 2026, 'records' => 1])
        ->and(collect($report->duplicates)->keyBy('source')->all())->toBe([
            'odk' => ['source' => 'odk', 'duplicate_assessment' => 0, 'against_other_source' => 0, 'cross_source_duplicates' => 0],
            'kobo' => ['source' => 'kobo', 'duplicate_assessment' => 1, 'against_other_source' => 1, 'cross_source_duplicates' => 2],
        ]);

    $this->artisan('edqa:backfill-report', ['--format' => 'md'])->assertSuccessful();
    $body = Storage::disk('exports')->get((string) collect(Storage::disk('exports')->files())->first());
    expect($body)->toContain('## KoboToolbox')->toContain('## ODK Central')->toContain('Duplicates between the sources')
        ->toContain('| KoboToolbox | 1 | 1 | 2 |');
});
