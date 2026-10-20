<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Actions\BackfillSubmissions;
use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Enums\AttachmentStatus;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Exceptions\BackfillRefused;
use App\Domain\Ingestion\Jobs\BackfillOdkSubmissionsJob;
use App\Domain\Ingestion\Jobs\FetchAttachmentsJob;
use App\Domain\Ingestion\Models\OdkAttachment;
use App\Domain\Ingestion\Models\OdkFormSync;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Odk\BackfillLock;
use App\Domain\Ingestion\Queries\BackfillReportQuery;
use App\Domain\Reporting\Aggregators\ShellStatusAggregator;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSubmissionSource;
use Tests\Support\FixtureWorld;
use Tests\Support\Rows;

// The historical backfill (ARCHITECTURE.md §5.6): the pull's page path from the start of the
// form, checkpointed, throttled, resumable. FixtureWorld gives 2026 Q1 (closed) and Q2 (open);
// today is in Q4 2026, so both are historical.

beforeEach(function (): void {
    FixtureWorld::build();
    CurrentRuleVersion::forget();
    $chikun = (int) DB::table('lgas')->where('code', 'CHK')->value('id');
    $kujama = (int) DB::table('wards')->where('code', 'CHK-KUJ')->value('id');
    foreach (range(1, 12) as $n) {
        Rows::facility(['code' => sprintf('FX-4%03d', $n), 'name' => "Backfill facility {$n}", 'lga_id' => $chikun, 'ward_id' => $kujama]);
    }
    config(['edqa.odk' => [...config('edqa.odk'),
        'url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'secret-for-tests', 'project_id' => '7', 'form_id' => 'dqa', 'page_size' => 3,
    ]]);
    config(['edqa.odk.backfill.throttle_seconds' => 0]);
    Sleep::fake();
    Storage::fake('odk');
    Storage::fake('exports');
    $this->source = new FakeSubmissionSource;
    app()->instance(SubmissionSource::class, $this->source);
});

/**
 * Entry $n: facility FX-4nnn, its own assessor, visited on $visit (local), submitted $n minutes
 * after 10:00 on 20 April 2026 (so the feed order is n).
 *
 * @return array<string, mixed>
 */
function bfEntry(int $n, string $visit = '2026-04-14', ?string $version = null, ?string $facility = null): array
{
    $entry = json_decode((string) file_get_contents(base_path('tests/Fixtures/odk/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
    $entry['__id'] = $entry['meta']['instanceID'] = "uuid:bf-{$n}";
    $entry['__system']['submissionDate'] = CarbonImmutable::parse('2026-04-20T10:00:00.000Z')->addMinutes($n)->format('Y-m-d\TH:i:s.v\Z');
    $entry['__system']['deviceId'] = "collect:bf{$n}";
    $entry['__system']['formVersion'] = $version ?? '2026.1';
    $entry['start'] = "{$visit}T09:00:00.000+01:00";
    $entry['end'] = "{$visit}T09:45:00.000+01:00";
    [$year, $month] = array_map(intval(...), explode('-', $visit));
    $entry['round_year'] = $year;
    $entry['round_quarter'] = intdiv($month - 1, 3) + 1;
    $entry['assessor_name'] = "Assessor {$n}";
    $entry['facility']['facility_code'] = $facility ?? sprintf('FX-4%03d', $n);

    return $entry;
}

function bfRun(?int $fromPage = null, ?int $throttle = null): OdkPullRun
{
    return app(BackfillSubmissions::class)->execute($fromPage, $throttle)->run->refresh();
}

function bfSync(): OdkFormSync
{
    return OdkFormSync::query()->where('project_id', 7)->where('form_id', 'dqa')->sole();
}

/** @return Builder<Submission> */
function bfStored(): Builder
{
    return Submission::query()->where('instance_id', 'like', 'uuid:bf-%');
}

it('loads every submission from the start, page by page, checkpointing each page', function (): void {
    $this->source->add(...array_map(fn (int $n): array => bfEntry($n), range(1, 7)));

    $run = bfRun(throttle: 2);

    expect($run->trigger_type)->toBe(PullTrigger::Backfill)
        ->and($run->outcome)->toBe(PullOutcome::Succeeded)
        ->and($run->only(['fetched', 'duplicates', 'accepted', 'flagged', 'quarantined']))->toBe(['fetched' => 7, 'duplicates' => 0, 'accepted' => 7, 'flagged' => 0, 'quarantined' => 0])
        ->and(bfStored()->count())->toBe(7)
        ->and(bfSync()->backfill_skip)->toBe(7)
        ->and(bfSync()->submission_count)->toBe(7)
        // The feed from the start, no filter, by $skip: pages at 0, 3 and 6.
        ->and(array_column($this->source->calls, 'skip'))->toBe([0, 3, 6])
        ->and(array_column($this->source->calls, 'since'))->toBe([null, null, null]);
    // The throttle between pages, not after the last.
    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2)->seconds()]);
});

it('throttles by ODK_BACKFILL_THROTTLE when no --throttle is given', function (): void {
    config(['edqa.odk.backfill.throttle_seconds' => 5]);
    $this->source->add(...array_map(fn (int $n): array => bfEntry($n), range(1, 4)));

    bfRun();

    Sleep::assertSequence([Sleep::for(5)->seconds()]);
});

it('resumes at the checkpoint after a crash at page N, with no duplicates', function (): void {
    $this->source->add(...array_map(fn (int $n): array => bfEntry($n), range(1, 7)));
    $crash = true;
    Assessment::creating(function (Assessment $assessment) use (&$crash): void {
        if ($crash && DB::table('facilities')->where('id', $assessment->facility_id)->value('code') === 'FX-4005') {
            throw new RuntimeException('Simulated crash in page 2');
        }
    });

    expect(fn () => bfRun())->toThrow(RuntimeException::class);
    $failed = OdkPullRun::query()->sole();
    expect($failed->outcome)->toBe(PullOutcome::Failed)
        ->and($failed->fetched)->toBe(3)
        ->and(bfSync()->backfill_skip)->toBe(3) // page 1 committed, page 2 rolled back
        ->and(bfStored()->count())->toBe(3);

    $crash = false;
    $this->source->calls = [];
    $run = bfRun();

    expect(array_column($this->source->calls, 'skip'))->toBe([3, 6]) // resumed at page 2
        ->and($run->only(['fetched', 'duplicates', 'accepted']))->toBe(['fetched' => 4, 'duplicates' => 0, 'accepted' => 4])
        ->and(bfStored()->count())->toBe(7)
        ->and(bfStored()->distinct()->count('instance_id'))->toBe(7)
        ->and(bfSync()->backfill_skip)->toBe(7);
});

it('starts at --from-page instead of the checkpoint', function (): void {
    $this->source->add(...array_map(fn (int $n): array => bfEntry($n), range(1, 7)));

    bfRun(fromPage: 2);

    expect(array_column($this->source->calls, 'skip'))->toBe([6])
        ->and(bfStored()->pluck('instance_id')->all())->toBe(['uuid:bf-7']);
});

it('stores nothing twice when it overlaps the incremental pull, either way round', function (): void {
    $this->source->add(...array_map(fn (int $n): array => bfEntry($n), range(1, 4)));
    app(PullSubmissions::class)->execute(PullTrigger::Scheduled);
    $this->source->add(...array_map(fn (int $n): array => bfEntry($n), range(5, 7)));

    $run = bfRun();

    expect($run->only(['fetched', 'duplicates', 'accepted']))->toBe(['fetched' => 7, 'duplicates' => 4, 'accepted' => 3])
        ->and(bfStored()->count())->toBe(7)
        ->and(Assessment::query()->whereIn('submission_id', bfStored()->select('id'))->count())->toBe(7);

    // The pull after it starts from what the backfill reached: only the boundary row again.
    $pull = app(PullSubmissions::class)->execute(PullTrigger::Scheduled)->refresh();
    expect($pull->only(['fetched', 'duplicates', 'accepted']))->toBe(['fetched' => 1, 'duplicates' => 1, 'accepted' => 0])
        ->and(bfSync()->last_pulled_at?->equalTo(CarbonImmutable::parse('2026-04-20T10:07:00Z')))->toBeTrue();
});

it('quarantines a visit outside every round, or in a closed one, under ROUND_WINDOW: never guessed into a round', function (): void {
    $this->source->add(
        bfEntry(1, '2025-08-12'),                 // no round in 2025
        bfEntry(2, '2026-02-10'),                 // 2026 Q1, closed
        bfEntry(3, '2026-04-14'),                 // 2026 Q2, open
    );

    bfRun();

    $codes = fn (string $id): array => array_column(QuarantinedRecord::query()->where('instance_id', $id)->sole()->failures, 'code');
    expect($codes('uuid:bf-1'))->toBe(['ROUND_WINDOW'])
        ->and($codes('uuid:bf-2'))->toBe(['ROUND_WINDOW'])
        ->and(QuarantinedRecord::query()->where('instance_id', 'uuid:bf-1')->sole()->round_id)->toBeNull()
        ->and(Assessment::query()->whereIn('submission_id', bfStored()->select('id'))->count())->toBe(1);
});

it('quarantines an unknown form version and never reads it through another map', function (): void {
    $this->source->add(bfEntry(1, version: '2019.9'), bfEntry(2, version: '2025.2'));

    bfRun();

    $record = QuarantinedRecord::query()->where('instance_id', 'uuid:bf-1')->sole();
    expect(array_column($record->failures, 'code'))->toBe(['UNKNOWN_FORM_VERSION'])
        ->and($record->parsed['form_version_known'])->toBeFalse()
        ->and($record->parsed['facility_ref'])->toBeNull()
        ->and($record->parsed['items'])->toBe([])
        ->and(bfStored()->where('instance_id', 'uuid:bf-1')->value('status'))->toBe(SubmissionStatus::Quarantined);
});

it('records attachments without fetching them', function (): void {
    Queue::fake([FetchAttachmentsJob::class]);
    $entry = bfEntry(1);
    $entry['__system']['attachmentsExpected'] = 1;
    $this->source->files['uuid:bf-1'] = ['register.png' => 'not fetched'];
    $this->source->add($entry);

    bfRun();

    expect(OdkAttachment::query()->sole()->status)->toBe(AttachmentStatus::Pending)
        ->and($this->source->downloads)->toBe([]);
    Queue::assertNothingPushed();
});

it('refuses to start before any historical round exists, and points at the survey', function (): void {
    DB::table('assessment_scores')->delete();
    DB::table('assessments')->delete();
    DB::table('rounds')->update(['year' => 2027]);
    $this->source->add(bfEntry(1));

    expect(fn () => bfRun())->toThrow(BackfillRefused::class);
    $this->artisan('edqa:backfill')->expectsOutputToContain('edqa:backfill --survey')->assertFailed();
    expect(OdkPullRun::query()->count())->toBe(0)
        ->and(bfStored()->count())->toBe(0)
        ->and($this->source->calls)->toBe([]);
});

it('refuses a second backfill while one runs', function (): void {
    $held = Cache::lock(BackfillLock::NAME, 60);
    expect($held->get())->toBeTrue();

    $this->artisan('edqa:backfill')->expectsOutputToContain(__('backfill.refused.already_running'))->assertFailed();
    $held->release();
});

it('surveys submissions per quarter and form version without writing anything', function (): void {
    $this->source->add(bfEntry(1, '2025-08-12', '2025.2'), bfEntry(2, '2025-08-20', '2025.2'), bfEntry(3, '2026-02-10'), bfEntry(4, '2026-04-14'), bfEntry(5, '2026-04-15', '2019.9'));
    $before = DB::table('submissions')->count();

    $this->artisan('edqa:backfill', ['--survey' => true])
        ->expectsOutputToContain('Survey: 5 submission(s)')
        ->expectsTable(['Quarter', 'Form version', 'Field map', 'Round', 'Submissions'], [
            ['2025 Q3', '2025.2', 'yes', 'none', 2],
            ['2026 Q1', '2026.1', 'yes', 'closed', 1],
            ['2026 Q2', '2019.9', 'NO', 'open', 1],
            ['2026 Q2', '2026.1', 'yes', 'open', 1],
        ])
        ->expectsOutputToContain('No round yet for these quarters (submissions in each): 2025 Q3 (2).')
        ->expectsOutputToContain('closed, so their visits would quarantine')
        ->expectsOutputToContain('No field map for 2019.9')
        ->assertSuccessful();

    expect(DB::table('submissions')->count())->toBe($before)
        ->and(OdkPullRun::query()->count())->toBe(0)
        ->and(OdkFormSync::query()->count())->toBe(0);
});

it('dry-runs the whole history without writing anything', function (): void {
    $this->source->add(...array_map(fn (int $n): array => bfEntry($n), range(1, 5)));
    $this->source->add(bfEntry(6, '2025-08-12'));
    $before = [DB::table('submissions')->count(), DB::table('assessments')->count(), DB::table('quarantined_records')->count()];

    $this->artisan('edqa:backfill', ['--dry-run' => true])
        ->expectsOutputToContain('Fetched 6; 0 already stored; of the rest 5 would be accepted, 0 flagged and 1 quarantined.')
        ->assertSuccessful();

    expect([DB::table('submissions')->count(), DB::table('assessments')->count(), DB::table('quarantined_records')->count()])->toBe($before)
        ->and(OdkPullRun::query()->count())->toBe(0);
});

it('runs on the low queue in chunks, each carrying on the same run from the checkpoint', function (): void {
    config(['edqa.odk.backfill.job_seconds' => 0]); // one page a job
    $this->source->add(...array_map(fn (int $n): array => bfEntry($n), range(1, 7)));

    $job = new BackfillOdkSubmissionsJob;
    expect($job->queue)->toBe('low');
    BackfillOdkSubmissionsJob::dispatchSync(); // the sync queue runs each next chunk at once

    $run = OdkPullRun::query()->sole();
    expect($run->outcome)->toBe(PullOutcome::Succeeded)
        ->and($run->fetched)->toBe(7)
        ->and(bfStored()->count())->toBe(7)
        ->and(array_column($this->source->calls, 'skip'))->toBe([0, 3, 6]);
});

it('queues the next chunk with its run when the time is up', function (): void {
    config(['edqa.odk.backfill.job_seconds' => 0]);
    Queue::fake([BackfillOdkSubmissionsJob::class]);
    $this->source->add(...array_map(fn (int $n): array => bfEntry($n), range(1, 7)));

    (new BackfillOdkSubmissionsJob(throttle: 3))->handle(app(BackfillSubmissions::class), app(BackfillLock::class));

    $run = OdkPullRun::query()->sole();
    expect($run->outcome)->toBeNull()
        ->and(bfSync()->backfill_skip)->toBe(3);
    Queue::assertPushed(BackfillOdkSubmissionsJob::class, fn (BackfillOdkSubmissionsJob $next): bool => $next->runId === $run->id && $next->fromPage === null && $next->throttle === 3);
});

it('keeps the status bar on the incremental pull: a backfill run is not "the last pull"', function (): void {
    Rows::pullRun(['trigger_type' => 'scheduled', 'outcome' => 'succeeded', 'started_at' => now()->subMinutes(6), 'finished_at' => now()->subMinutes(5)]);
    Rows::pullRun(['trigger_type' => 'backfill', 'outcome' => 'failed', 'started_at' => now()->subMinutes(2), 'finished_at' => now()->subMinute(), 'error' => 'RuntimeException: boom']);

    $status = app(ShellStatusAggregator::class)->sync();

    expect($status['state'])->toBe('ok')
        ->and($status['lastError'])->toBeNull()
        ->and($status['minutesSinceSuccess'])->toBe(5);
});

it('writes a backfill report whose numbers reconcile with the tables', function (string $format): void {
    // A mix: accepted, flagged (12-minute visit), outside every round, unknown version, closed
    // round, a second visit to a facility (duplicate), and an edit that supersedes one.
    $short = bfEntry(3);
    $short['end'] = '2026-04-14T09:12:00.000+01:00';
    $original = bfEntry(8);
    $this->source->add(bfEntry(1), bfEntry(2), $short, bfEntry(4, '2025-08-12'), bfEntry(5, version: '2019.9'), bfEntry(6, '2026-02-10'),
        bfEntry(7, facility: 'FX-4001'), $original);
    bfRun();
    $edit = $original;
    $edit['meta'] = ['instanceID' => 'uuid:bf-8-v2', 'deprecatedID' => 'uuid:bf-8'];
    $edit['__system']['updatedAt'] = '2026-05-01T08:00:00.000Z';
    $this->source->add($edit);
    app(PullSubmissions::class)->execute(PullTrigger::Scheduled);

    $report = app(BackfillReportQuery::class)->build();
    $total = collect($report->rounds)->firstWhere('total', true);
    $q2 = (int) DB::table('rounds')->where(['year' => 2026, 'quarter' => 2])->value('id');
    $roundRow = collect($report->rounds)->firstWhere('round_id', $q2);
    $noRound = collect($report->rounds)->first(fn (array $r): bool => ! $r['total'] && $r['round_id'] === null);
    $status = fn (string $s): int => Submission::query()->where('status', $s)->count();

    expect($report->submissions)->toBe(Submission::query()->count())
        ->and($report->superseded)->toBe($status('superseded'))->toBe(1)
        ->and($total['pulled'] + $report->superseded)->toBe($report->submissions)
        ->and($total['accepted'])->toBe($status('accepted'))
        ->and($total['flagged'])->toBe($status('flagged'))
        ->and($total['quarantined'])->toBe($status('quarantined'))
        // Every Q2 assessment is in Q2's row (FixtureWorld's own have unprocessed stand-in submissions).
        ->and($roundRow['accepted'] + $roundRow['flagged'] + $roundRow['unprocessed'])->toBe(Assessment::query()->where('round_id', $q2)->count())
        ->and($roundRow['accepted'] + $roundRow['flagged'])->toBe(Assessment::query()->where('round_id', $q2)->whereHas('submission', fn ($q) => $q->whereIn('status', ['accepted', 'flagged']))->count())
        ->and($roundRow['quarantined'])->toBe(QuarantinedRecord::query()->where('round_id', $q2)->where('status', 'open')->count())
        // Only the 2025 visit has no round; the unknown version is placed by its submission date (Q2).
        ->and($noRound['quarantined'])->toBe(1);
    foreach ($report->byVersion as $row) {
        $records = QuarantinedRecord::query()->where('status', 'open')
            ->whereHas('submission', fn ($q) => $q->where('form_version', $row['version']))
            ->whereJsonContains('failures', [['code' => $row['code']]])->count();
        expect($row['records'])->toBe($records);
    }
    expect(collect($report->byYear)->where('code', 'ROUND_WINDOW')->pluck('records', 'year')->all())->toBe([2025 => 1, 2026 => 1])
        ->and(collect($report->byVersion)->firstWhere('code', 'UNKNOWN_FORM_VERSION'))->toMatchArray(['version' => '2019.9', 'records' => 1])
        ->and(collect($report->examples)->where('code', 'ROUND_WINDOW')->count())->toBeGreaterThanOrEqual(1);

    $this->artisan('edqa:backfill-report', ['--format' => $format])->expectsOutputToContain('Backfill report written to')->assertSuccessful();
    $files = Storage::disk('exports')->files();
    expect($files)->toHaveCount(1)->and($files[0])->toEndWith(".{$format}");
    $body = Storage::disk('exports')->get($files[0]);
    if ($format === 'md') {
        expect($body)->toContain('# Backfill report')->toContain('| Q2 2026 |')->toContain('ROUND_WINDOW')->toContain('UNKNOWN_FORM_VERSION');
    } else {
        expect(str_getcsv(strtok((string) $body, "\n"), escape: ''))->toBe(['section', 'rule', 'round', 'year', 'version', 'measure', 'value', 'detail'])
            ->and($body)->toContain('by_round,,total,,,pulled,'.$total['pulled']);
    }
})->with(['md', 'csv']);

it('refuses a report format it cannot write', function (): void {
    $this->artisan('edqa:backfill-report', ['--format' => 'xlsx'])->expectsOutputToContain('Give --format as one of: md, csv.')->assertFailed();
    expect(Storage::disk('exports')->files())->toBe([]);
});
