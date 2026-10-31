<?php

declare(strict_types=1);

use App\Domain\Ingestion\Actions\FetchAttachments;
use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Actions\ReconcileSource;
use App\Domain\Ingestion\Enums\AttachmentStatus;
use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Exceptions\KoboAuthFailed;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Models\SubmissionAttachment;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\LgaSeeder;
use Database\Seeders\RoundSeeder;
use Database\Seeders\ScoringRuleVersionSeeder;
use Database\Seeders\WardSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Rows;

// The local mock KoboToolbox (docker/kobo-mock) beside the mock ODK Central, and the generator for
// both (edqa:source-mock:generate). Http::fake routes each host to its mock's own handler, so the
// real adapters, normalisers, parser and pipeline run against them.

require_once dirname(__DIR__, 4).'/docker/odk-mock/handler.php';
require_once dirname(__DIR__, 4).'/docker/kobo-mock/handler.php';

beforeEach(function (): void {
    $this->seed([LgaSeeder::class, ScoringRuleVersionSeeder::class, WardSeeder::class, FacilitySeeder::class, RoundSeeder::class]);
    CurrentRuleVersion::forget();
    $id = bin2hex(random_bytes(4));
    $this->odkMock = sys_get_temp_dir()."/odk-mock-{$id}";
    $this->koboMock = sys_get_temp_dir()."/kobo-mock-{$id}";
    config(['edqa.odk.mock_path' => $this->odkMock, 'edqa.kobo.mock_path' => $this->koboMock]);
    config(['edqa.sources.odk' => [...config('edqa.sources.odk'),
        'url' => 'https://odk-mock.test', 'email' => 'mock@odk.local', 'password' => 'mock-password', 'project_id' => '1', 'form_id' => 'dqa', 'page_size' => 50,
    ]]);
    config(['edqa.sources.kobo' => [...config('edqa.sources.kobo'),
        'url' => 'https://kobo-mock.test', 'token' => 'mock-kobo-token', 'asset_uid' => 'aKdDqaMock2026', 'page_size' => 50,
    ]]);
    config(['edqa.heartbeats.pull' => ['odk' => null, 'kobo' => null]]);
    Rows::sourceId('odk');
    Rows::sourceId('kobo');
    app(SourceRegistry::class)->flush();
    Storage::fake('attachments');
    [$odk, $kobo] = [$this->odkMock, $this->koboMock];
    Http::fake(function (Request $request) use ($odk, $kobo) {
        $headers = [];
        foreach ($request->headers() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }
        $url = parse_url($request->url());
        $target = ($url['path'] ?? '').(isset($url['query']) ? '?'.$url['query'] : '');
        $response = ($url['host'] ?? '') === 'kobo-mock.test'
            ? kobo_mock_handle($request->method(), $target, $headers, $kobo)
            : odk_mock_handle($request->method(), $target, $headers, $request->body(), $odk);

        return Http::response(isset($response['file']) ? file_get_contents($response['file']) : $response['body'], $response['status'], $response['headers']);
    });
});

afterEach(function (): void {
    File::deleteDirectory($this->odkMock);
    File::deleteDirectory($this->koboMock);
});

it('writes KPI v2 data rows for the mock, with the mix asked for', function (): void {
    $this->artisan('edqa:source-mock:generate', ['source' => 'kobo', '--count' => 60, '--broken' => 10])
        ->expectsOutputToContain('Wrote 47 valid, 2 to be flagged and 10 to be quarantined, plus 1 edits (staged) and 2 photo(s)')
        ->assertSuccessful();

    $rows = array_map(fn (string $f): array => json_decode((string) file_get_contents($f), true), glob($this->koboMock.'/submissions/*.json'));
    expect($rows)->toHaveCount(59)
        ->and(glob($this->koboMock.'/edits/*.json'))->toHaveCount(1)
        ->and(glob($this->koboMock.'/attachments/*/*.jpg'))->toHaveCount(2)
        ->and($rows[0])->toHaveKeys(['_id', '_uuid', '_submission_time', '__version__', 'meta/instanceID', 'facility/facility_code', 'availability/avail_m1_nhmis_summary', '_attachments'])
        ->and($rows[0]['__version__'])->toBe('vKd26Q1aBcDeFgHiJkLmNo')
        ->and($rows[0]['_submission_time'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/');
});

it('pulls the mock through the real KoboSource: the broken ones quarantined, photos fetched from the Kobo host', function (): void {
    $this->artisan('edqa:source-mock:generate', ['source' => 'kobo', '--count' => 30, '--broken' => 5])->assertSuccessful();

    $run = app(PullSubmissions::class)->execute('kobo')->refresh();

    expect([$run->outcome, $run->fetched, $run->quarantined])->toBe([PullOutcome::Succeeded, 29, 5])
        ->and($run->accepted + $run->flagged)->toBe(24)
        ->and(QuarantinedRecord::query()->where('source_id', Rows::sourceId('kobo'))->count())->toBe(5);

    $withPhoto = SubmissionAttachment::query()->pluck('submission_id')->unique()->values();
    expect($withPhoto)->toHaveCount(2);
    foreach ($withPhoto as $id) {
        app(FetchAttachments::class)->execute($id);
    }
    expect(SubmissionAttachment::query()->where('status', AttachmentStatus::Fetched)->count())->toBe(2);
});

it('serves the staged edits once applied, in place, which only the reconcile sweep catches', function (): void {
    $this->artisan('edqa:source-mock:generate', ['source' => 'kobo', '--count' => 60, '--broken' => 0])->assertSuccessful();
    app(PullSubmissions::class)->execute('kobo');

    $this->artisan('edqa:source-mock:generate', ['source' => 'kobo', '--edits' => true])->expectsOutputToContain('edits')->assertSuccessful();
    $pull = app(PullSubmissions::class)->execute('kobo')->refresh();
    $reconcile = app(ReconcileSource::class)->execute('kobo')->refresh();

    // The pull sees an edit only if it is the newest row (its _submission_time is the cursor's);
    // the sweep catches it either way, and never twice.
    expect($pull->parsed + $reconcile->parsed)->toBe(1)
        ->and(Submission::query()->where('source_id', Rows::sourceId('kobo'))->where('status', SubmissionStatus::Superseded)->count())->toBe(1);
});

it('writes overlapping visits on both mocks: copies counted as cross-source duplicates, re-entries as DUPLICATE_ASSESSMENT', function (): void {
    $this->artisan('edqa:source-mock:generate', ['source' => 'odk', '--count' => 20, '--broken' => 0])->assertSuccessful();
    $this->artisan('edqa:source-mock:generate', ['source' => 'kobo', '--count' => 20, '--broken' => 0, '--overlap' => 4])
        ->expectsOutputToContain('4 visit(s) also on ODK Central')->assertSuccessful();

    $odk = app(PullSubmissions::class)->execute('odk')->refresh();
    $kobo = app(PullSubmissions::class)->execute('kobo')->refresh();

    $duplicates = QuarantinedRecord::query()->get()->filter(fn (QuarantinedRecord $r): bool => in_array('DUPLICATE_ASSESSMENT', array_column($r->failures, 'code'), true));
    expect($odk->quarantined)->toBe(0)
        ->and($kobo->cross_source_duplicates)->toBe(2)
        ->and($duplicates)->toHaveCount(2)
        ->and($duplicates->first()->failures[0]['detail'])->toContain('from ODK Central; this one from KoboToolbox');
});

it('refuses overlap when the other mock is empty, and refuses outside local', function (): void {
    $this->artisan('edqa:source-mock:generate', ['source' => 'kobo', '--count' => 5, '--overlap' => 2])
        ->expectsOutputToContain('ODK Central mock has no visits to overlap')->assertFailed();

    app()->detectEnvironment(fn (): string => 'production');
    $this->artisan('edqa:source-mock:generate', ['source' => 'kobo'])->expectsOutputToContain('local only')->assertFailed();
});

it('answers only a token it knows, and only GETs', function (): void {
    $this->artisan('edqa:source-mock:generate', ['source' => 'kobo', '--count' => 3, '--broken' => 0])->assertSuccessful();

    expect(kobo_mock_handle('POST', '/api/v2/assets/aKdDqaMock2026/data/', ['authorization' => 'Token mock-kobo-token'], $this->koboMock)['status'])->toBe(405)
        ->and(kobo_mock_handle('GET', '/api/v2/assets/nope/data/', ['authorization' => 'Token mock-kobo-token'], $this->koboMock)['status'])->toBe(404)
        ->and(app(SourceRegistry::class)->adapter('kobo')->ping()->form)->toBe('Data Quality Assessment (mock)');

    config(['edqa.sources.kobo.token' => 'wrong']);
    expect(fn () => app(SourceRegistry::class)->adapter('kobo')->ping())->toThrow(KoboAuthFailed::class);
});
