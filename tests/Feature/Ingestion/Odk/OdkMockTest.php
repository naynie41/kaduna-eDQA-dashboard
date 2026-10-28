<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Enums\AttachmentStatus;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Exceptions\OdkAuthFailed;
use App\Domain\Ingestion\Exceptions\OdkUnexpectedResponse;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Models\SubmissionAttachment;
use App\Domain\Ingestion\SubmissionParser;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Support\OdkMock\MockSubmissionGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\LgaSeeder;
use Database\Seeders\RoundSeeder;
use Database\Seeders\ScoringRuleVersionSeeder;
use Database\Seeders\WardSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Rows;

// The local mock ODK Central (docker/odk-mock) and its generator. Http::fake routes every request
// to the mock's own handler, so the real OdkCentralSource, parser and pipeline run against it.

require_once dirname(__DIR__, 4).'/docker/odk-mock/handler.php';

beforeEach(function (): void {
    $this->seed([LgaSeeder::class, ScoringRuleVersionSeeder::class, WardSeeder::class, FacilitySeeder::class, RoundSeeder::class]);
    CurrentRuleVersion::forget();
    $this->mock = sys_get_temp_dir().'/odk-mock-'.bin2hex(random_bytes(4));
    config(['edqa.sources.odk' => [...config('edqa.sources.odk'),
        'url' => 'https://odk-mock.test', 'email' => 'mock@odk.local', 'password' => 'mock-password', 'project_id' => '1', 'form_id' => 'dqa',
    ]]);
    config(['edqa.odk.mock_path' => $this->mock]);
    Sleep::fake();
    Storage::fake('attachments');
    $mock = $this->mock;
    Http::fake(function (Request $request) use ($mock) {
        $headers = [];
        foreach ($request->headers() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }
        $url = parse_url($request->url());
        $response = odk_mock_handle($request->method(), ($url['path'] ?? '').(isset($url['query']) ? '?'.$url['query'] : ''), $headers, $request->body(), $mock);

        return Http::response(isset($response['file']) ? file_get_contents($response['file']) : $response['body'], $response['status'], $response['headers']);
    });
});

afterEach(function (): void {
    File::deleteDirectory($this->mock);
});

it('writes the mix asked for: broken, flagged, edits and photos, for facilities not yet visited', function (): void {
    $this->artisan('edqa:odk-mock:generate', ['--count' => 60, '--broken' => 10])
        ->expectsOutputToContain('Wrote 47 valid, 2 to be flagged and 10 to be quarantined, plus 1 edits (staged) and 2 photo(s)')
        ->assertSuccessful();

    expect(glob($this->mock.'/submissions/*.json'))->toHaveCount(59)
        ->and(glob($this->mock.'/edits/*.json'))->toHaveCount(1)
        ->and(glob($this->mock.'/attachments/*/*.jpg'))->toHaveCount(2)
        ->and(json_decode((string) file_get_contents($this->mock.'/config.json'), true)['edits_applied'])->toBeFalse();
});

it('serves them through the real source, and the pipeline holds exactly the broken ones', function (): void {
    $this->artisan('edqa:odk-mock:generate', ['--count' => 60, '--broken' => 10])->assertSuccessful();
    $source = app(SubmissionSource::class);
    $parser = app(SubmissionParser::class);

    $statuses = [];
    $codes = [];
    $skip = 0;
    do {
        $page = $source->submissionsPage(null, 25, $skip);
        foreach ($page->rows as $raw) {
            $parsed = odkParse($raw);
            $submission = Submission::query()->create(['source_id' => Rows::sourceId(), 'external_id' => $parsed->instanceId, 'instance_id' => $parsed->instanceId, 'deprecated_id' => $parsed->deprecatedId, 'form_ref' => 'dqa', 'form_version' => $parsed->formVersion,
                'payload' => $raw, 'submitted_at' => $parsed->submittedAt, 'received_at' => now(), 'status' => SubmissionStatus::Received]);
            $outcome = app(ProcessParsedBatch::class)->execute([[$submission, $parsed]])[0];
            $statuses[] = $outcome->status->value;
            if ($outcome->quarantinedRecordId !== null) {
                $codes = [...$codes, ...array_column(QuarantinedRecord::query()->findOrFail($outcome->quarantinedRecordId)->failures, 'code')];
            }
        }
        $skip += count($page->rows);
    } while ($page->hasMore);

    expect($skip)->toBe(59)
        ->and(array_count_values($statuses))->toEqual(['accepted' => 47, 'flagged' => 2, 'quarantined' => 10])
        ->and(array_values(array_unique($codes)))->toEqualCanonicalizing(['LGA_UNKNOWN', 'FACILITY_UNKNOWN', 'SCORE_RANGE', 'ITEMS_INCOMPLETE', 'COLUMN_DRIFT', 'END_BEFORE_START', 'ROUND_WINDOW', 'FACILITY_LGA_MISMATCH'])
        ->and($codes)->not->toContain('DUPLICATE_ASSESSMENT', 'UNKNOWN_FORM_VERSION', 'ASSESSOR_VOLUME');
});

it('answers ping, the versions and the attachments like Central', function (): void {
    $this->artisan('edqa:odk-mock:generate', ['--count' => 30, '--broken' => 0])->assertSuccessful();
    $source = app(SubmissionSource::class);

    $ping = $source->ping();
    $versions = array_map(fn ($v) => $v->version, $source->formVersions());
    $withPhoto = collect($source->submissionsPage(null, 100, 0)->rows)->first(fn (array $row): bool => isset($row['register_photo']));
    $refs = $source->attachmentList($withPhoto['meta']['instanceID']);
    $sink = tempnam(sys_get_temp_dir(), 'att');
    $download = $source->downloadAttachment($withPhoto['meta']['instanceID'], $refs[0]->name, $sink);

    expect([$ping->account, $ping->project, $ping->currentVersion])->toBe(['eDQA portal (mock)', 'Kaduna DQA (mock)', '2026.1'])
        ->and($versions)->toBe(['2026.1', '2025.2'])
        ->and($refs[0]->name)->toBe($withPhoto['register_photo'])
        ->and($download->mime)->toBe('image/jpeg')
        ->and(substr((string) file_get_contents($sink), 0, 2))->toBe("\xFF\xD8"); // a JPEG
    @unlink($sink);
});

it('filters on submissionDate, pages, and refuses a wrong password or form', function (): void {
    $this->artisan('edqa:odk-mock:generate', ['--count' => 40, '--broken' => 0])->assertSuccessful();
    $source = app(SubmissionSource::class);
    $all = $source->submissionsPage(null, 100, 0)->rows;
    $dates = array_map(fn (array $r): string => $r['__system']['submissionDate'], $all);
    $middle = CarbonImmutable::parse($dates[19]);

    $after = $source->submissionsPage($middle, 100, 0);

    expect($dates)->toBe(collect($dates)->sort()->values()->all())
        ->and($after->totalCount)->toBe(count(array_filter($dates, fn (string $d): bool => CarbonImmutable::parse($d)->gt($middle))))
        ->and($source->submissionsPage(null, 10, 30)->hasMore)->toBeFalse();

    config(['edqa.sources.odk.form_id' => 'other']);
    expect(fn () => app(SubmissionSource::class)->formVersions())->toThrow(OdkUnexpectedResponse::class);
    config(['edqa.sources.odk.form_id' => 'dqa', 'edqa.sources.odk.password' => 'wrong']);
    Cache::flush();
    expect(fn () => app(SubmissionSource::class)->formVersions())->toThrow(OdkAuthFailed::class);
});

it('shows an edit only once applied: same __id, a new instance ID that deprecates the old one', function (): void {
    $this->artisan('edqa:odk-mock:generate', ['--count' => 60, '--broken' => 0])->assertSuccessful();
    $source = app(SubmissionSource::class);
    $edit = json_decode((string) file_get_contents(glob($this->mock.'/edits/*.json')[0]), true);

    $before = collect($source->submissionsPage(null, 100, 0)->rows)->firstWhere('__id', $edit['__id']);
    $this->artisan('edqa:odk-mock:generate', ['--apply-edits' => true])->expectsOutputToContain('Edits applied')->assertSuccessful();
    $after = collect($source->submissionsPage(null, 100, 0)->rows)->firstWhere('__id', $edit['__id']);

    expect($before['meta'])->toBe(['instanceID' => $edit['__id']])
        ->and($after['meta'])->toBe(['instanceID' => $edit['meta']['instanceID'], 'deprecatedID' => $edit['__id']])
        ->and($after['__system']['submissionDate'])->toBe($before['__system']['submissionDate'])
        ->and($after['__system']['updatedAt'])->not->toBeNull()
        ->and($source->submissionsPage(null, 100, 0)->totalCount)->toBe(59); // still one row per submission

    // The edit is found by updatedAt, not by submissionDate (which it keeps).
    $handled = odk_mock_handle('GET', '/v1/projects/1/forms/dqa.svc/Submissions?%24filter='.rawurlencode('__system/updatedAt gt '.$before['__system']['submissionDate']).'&%24count=true', ['authorization' => 'Bearer mock-'.substr(hash('sha256', 'mock@odk.local'), 0, 32)], '', $this->mock);
    expect(json_decode($handled['body'], true)['@odata.count'])->toBe(1);
});

it('gives the same data for the same seed', function (): void {
    $generator = new MockSubmissionGenerator($this->mock);
    $generator->generate(20, 3, null, '2026.1', 7);
    $first = array_map('file_get_contents', glob($this->mock.'/submissions/*.json'));
    $generator->generate(20, 3, null, '2026.1', 7);

    expect(array_map('file_get_contents', glob($this->mock.'/submissions/*.json')))->toBe($first);
});

it('writes an older version through its own field map, and the parser reads it back', function (): void {
    $this->artisan('edqa:odk-mock:generate', ['--count' => 5, '--broken' => 0, '--form-version' => '2025.2'])->assertSuccessful();
    $raw = json_decode((string) file_get_contents(glob($this->mock.'/submissions/*.json')[0]), true);

    expect($raw)->toHaveKey('fac')->not->toHaveKey('facility')
        ->and($raw['availability'])->toHaveKey('avail_m1_nhmis_form')
        ->and(odkParse($raw)->facilityRef)->toBe($raw['fac']['fac_code']);
});

it('refuses outside local and testing, and a round it cannot read', function (): void {
    $this->artisan('edqa:odk-mock:generate', ['--round' => 'Q2-2026'])->expectsOutputToContain('Give the round as 2026Q2')->assertFailed();

    app()->detectEnvironment(fn (): string => 'production');
    $this->artisan('edqa:odk-mock:generate')->assertFailed();
    expect(is_dir($this->mock))->toBeFalse();
});

it('is pulled end to end: three pages, the broken ones quarantined, an edit applied in place, nothing twice', function (): void {
    config(['edqa.sources.odk.page_size' => 25]);
    $this->artisan('edqa:odk-mock:generate', ['--count' => 60, '--broken' => 10])->assertSuccessful();
    $pull = fn () => app(PullSubmissions::class)->execute(PullTrigger::Scheduled)->refresh();

    $first = $pull();
    expect($first->outcome)->toBe(PullOutcome::Succeeded)
        ->and($first->only(['fetched', 'duplicates', 'accepted', 'flagged', 'quarantined']))->toBe(['fetched' => 59, 'duplicates' => 0, 'accepted' => 47, 'flagged' => 2, 'quarantined' => 10])
        ->and(Submission::query()->count())->toBe(59)
        ->and(QuarantinedRecord::query()->count())->toBe(10);
    // Its two photos: recorded by the pull, fetched (the sync test queue) as JPEGs.
    $photos = SubmissionAttachment::query()->get();
    expect($photos)->toHaveCount(2)
        ->and($photos->every(fn (SubmissionAttachment $a): bool => $a->status === AttachmentStatus::Fetched && $a->mime === 'image/jpeg'))->toBeTrue();
    foreach ($photos as $photo) {
        Storage::disk('attachments')->assertExists((string) $photo->path);
    }
    $assessments = Assessment::query()->count();

    $this->artisan('edqa:odk-mock:generate', ['--apply-edits' => true])->assertSuccessful();
    $second = $pull();
    expect($second->accepted)->toBe(1)
        ->and(Assessment::query()->count())->toBe($assessments)
        ->and(Submission::query()->where('status', SubmissionStatus::Superseded)->count())->toBe(1);

    $third = $pull();
    expect([$third->accepted, $third->flagged, $third->quarantined])->toBe([0, 0, 0])
        ->and(Submission::query()->count())->toBe(60);
});

it('spreads the data over a span of quarters, the oldest in an older version, before their rounds exist', function (): void {
    $this->artisan('edqa:odk-mock:generate', ['--count' => 60, '--broken' => 6, '--quarters' => '2025Q1-2025Q3', '--older-quarters' => 1])
        ->assertSuccessful();

    $byQuarter = [];
    $parser = app(SubmissionParser::class);
    foreach (glob($this->mock.'/submissions/*.json') ?: [] as $file) {
        $parsed = odkParse(json_decode((string) file_get_contents($file), true));
        $day = $parsed->visitDate();
        $byQuarter["{$day->year}Q{$day->quarter}"][$parsed->formVersion] = ($byQuarter["{$day->year}Q{$day->quarter}"][$parsed->formVersion] ?? 0) + 1;
    }

    // 20 visits a quarter; the "outside every round" defect lands three years earlier.
    expect(array_keys($byQuarter))->toContain('2025Q1', '2025Q2', '2025Q3')
        ->and(array_keys($byQuarter['2025Q1']))->toBe(['2025.2'])
        ->and(array_keys($byQuarter['2025Q2']))->toBe(['2026.1'])
        ->and(array_sum(array_map('array_sum', $byQuarter)))->toBe(glob($this->mock.'/submissions/*.json') === false ? 0 : count(glob($this->mock.'/submissions/*.json')));
    $this->artisan('edqa:odk-mock:generate', ['--quarters' => '2025Q3-2025Q1'])->expectsOutputToContain('Give the quarters as 2024Q1-2025Q2')->assertFailed();
});
