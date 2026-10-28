<?php

declare(strict_types=1);

use App\Domain\Ingestion\Actions\FetchAttachments;
use App\Domain\Ingestion\Actions\PullSubmissions;
use App\Domain\Ingestion\Actions\RecordAttachments;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\AttachmentRef;
use App\Domain\Ingestion\Enums\AttachmentStatus;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Exceptions\OdkUnreachable;
use App\Domain\Ingestion\Jobs\FetchAttachmentsJob;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Models\SubmissionAttachment;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSubmissionSource;
use Tests\Support\FixtureWorld;
use Tests\Support\Rows;

// ODK attachments (ARCHITECTURE.md §5.3, SECURITY.md §7): recorded by the pull, fetched on the low
// queue to the private odk disk (faked here), checked by name, type and size, and served only
// through the authenticated route.

const ATT_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

beforeEach(function (): void {
    FixtureWorld::build();
    CurrentRuleVersion::forget();
    $chikun = (int) DB::table('lgas')->where('code', 'CHK')->value('id');
    $kujama = (int) DB::table('wards')->where('code', 'CHK-KUJ')->value('id');
    foreach (range(1, 5) as $n) {
        Rows::facility(['code' => sprintf('FX-3%03d', $n), 'name' => "Attachment facility {$n}", 'lga_id' => $chikun, 'ward_id' => $kujama]);
    }
    config(['edqa.sources.odk' => [...config('edqa.sources.odk'),
        'url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'secret-for-tests', 'project_id' => '7', 'form_id' => 'dqa', 'page_size' => 10,
    ]]);
    Storage::fake('attachments');
    $this->source = new FakeSubmissionSource;
    app()->instance(SubmissionSource::class, $this->source);
    $this->admin = User::factory()->withTwoFactor()->create();
});

/**
 * Entry $n (FX-3nnn) expecting $files, which the fake ODK serves.
 *
 * @param  array<string, string>  $files  filename => bytes
 * @return array<string, mixed>
 */
function attEntry(object $test, int $n, array $files = []): array
{
    $entry = json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/odk/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
    $entry['__id'] = $entry['meta']['instanceID'] = "uuid:att-{$n}";
    $entry['__system']['submissionDate'] = CarbonImmutable::parse('2026-04-15T10:00:00.000Z')->addMinutes($n)->format('Y-m-d\TH:i:s.v\Z');
    $entry['__system']['deviceId'] = "collect:att{$n}";
    $entry['__system']['attachmentsExpected'] = count($files);
    $entry['assessor_name'] = "Assessor {$n}";
    $entry['facility']['facility_code'] = sprintf('FX-3%03d', $n);
    if ($files !== []) {
        $entry['register_photo'] = 'photo'; // the name itself comes from Central's attachment list
        $test->source->files[$entry['__id']] = $files;
    }

    return $entry;
}

function attPull(): void
{
    app(PullSubmissions::class)->execute(PullTrigger::Scheduled);
}

function attSubmission(int $n): Submission
{
    return Submission::query()->where('instance_id', "uuid:att-{$n}")->sole();
}

it('records what ODK expects during the pull and fetches it to the private disk', function (): void {
    $this->source->add(attEntry($this, 1, ['register.png' => base64_decode(ATT_PNG)]), attEntry($this, 2));

    attPull();

    $attachment = SubmissionAttachment::query()->sole();
    expect($attachment->submission_id)->toBe(attSubmission(1)->id)
        ->and($attachment->filename)->toBe('register.png')
        ->and($attachment->status)->toBe(AttachmentStatus::Fetched)
        ->and($attachment->mime)->toBe('image/png')
        ->and($attachment->size)->toBe(strlen(base64_decode(ATT_PNG)))
        ->and($attachment->path)->toBe('odk/uuid_att-1/register.png')
        ->and($attachment->fetched_at)->not->toBeNull()
        ->and($this->source->downloads)->toBe(['uuid:att-1/register.png']);
    Storage::disk('attachments')->assertExists('odk/uuid_att-1/register.png');
    expect(Storage::disk('attachments')->get('odk/uuid_att-1/register.png'))->toBe(base64_decode(ATT_PNG))
        ->and(Storage::disk('attachments')->allFiles('.incoming'))->toBe([]);
});

it('queues the fetch on the low queue, once per submission with files, after the page commits', function (): void {
    Queue::fake([FetchAttachmentsJob::class]);
    $this->source->add(attEntry($this, 1, ['a.png' => base64_decode(ATT_PNG), 'b.png' => base64_decode(ATT_PNG)]), attEntry($this, 2), attEntry($this, 3, ['c.png' => base64_decode(ATT_PNG)]));

    attPull();

    Queue::assertPushedOn('low', FetchAttachmentsJob::class);
    Queue::assertPushedTimes(FetchAttachmentsJob::class, 2);
    Queue::assertPushed(FetchAttachmentsJob::class, fn (FetchAttachmentsJob $job): bool => $job->submissionId === attSubmission(1)->id);
    expect(SubmissionAttachment::query()->where('status', AttachmentStatus::Pending)->count())->toBe(3);

    // The same pull again records nothing new and queues nothing.
    attPull();
    Queue::assertPushedTimes(FetchAttachmentsJob::class, 2);
    expect(SubmissionAttachment::query()->count())->toBe(3);
});

it('only records attachments when asked to record without fetching (backfill)', function (): void {
    Queue::fake([FetchAttachmentsJob::class]);
    $submission = Submission::factory()->create();

    $ids = app(RecordAttachments::class)->execute([$submission->id => [new AttachmentRef('trace.geojson', true)]]);

    expect($ids)->toBe([$submission->id])
        ->and(SubmissionAttachment::query()->sole()->status)->toBe(AttachmentStatus::Pending);
    Queue::assertNothingPushed();
});

it('accepts the allowed types by their bytes', function (string $name, string $bytes, string $mime): void {
    $this->source->add(attEntry($this, 1, [$name => $bytes]));

    attPull();

    expect(SubmissionAttachment::query()->sole()->only(['status', 'mime']))->toBe(['status' => AttachmentStatus::Fetched, 'mime' => $mime]);
})->with([
    'png' => ['photo.png', base64_decode(ATT_PNG), 'image/png'],
    'GeoJSON trace' => ['trace.geojson', '{"type":"FeatureCollection","features":[]}', 'application/geo+json'],
    'csv' => ['readings.csv', "month,value\n1,20\n", 'text/csv'],
    'xml' => ['form.xml', '<?xml version="1.0"?><data id="dqa"/>', 'application/xml'],
]);

it('rejects and deletes a file whose bytes are not an allowed type, whatever its name says', function (string $name, string $bytes): void {
    $this->source->add(attEntry($this, 1, [$name => $bytes]));

    attPull();

    $attachment = SubmissionAttachment::query()->sole();
    expect($attachment->status)->toBe(AttachmentStatus::Rejected)
        ->and($attachment->reason)->toBe('type_not_allowed')
        ->and($attachment->path)->toBeNull()
        ->and(Storage::disk('attachments')->allFiles())->toBe([]);
})->with([
    'php named jpg' => ['register.jpg', '<?php echo "not a photo"; ?>'],
    'html named png' => ['photo.png', '<html><script>alert(1)</script></html>'],
    'json that is not GeoJSON' => ['trace.geojson', '{"hello":"world"}'],
    'executable' => ['tool.csv', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff"],
]);

it('rejects a file larger than the cap, and deletes it', function (): void {
    config(['edqa.odk.attachments.max_bytes' => 10]);
    $this->source->add(attEntry($this, 1, ['photo.png' => base64_decode(ATT_PNG)]));

    attPull();

    expect(SubmissionAttachment::query()->sole()->only(['status', 'reason']))->toBe(['status' => AttachmentStatus::Rejected, 'reason' => 'too_large'])
        ->and(Storage::disk('attachments')->allFiles())->toBe([]);
});

it('refuses a file name that could leave its directory, without downloading it', function (string $name): void {
    $this->source->add(attEntry($this, 1, [$name => base64_decode(ATT_PNG)]));

    attPull();

    expect(SubmissionAttachment::query()->sole()->only(['status', 'reason']))->toBe(['status' => AttachmentStatus::Rejected, 'reason' => 'unsafe_name'])
        ->and($this->source->downloads)->toBe([])
        ->and(Storage::disk('attachments')->allFiles())->toBe([]);
})->with(['../../.env', '..\\..\\x.png', '.htaccess', 'a/b.png', "nul\0.png", 'photo..png']);

it('never fetches a fetched file again', function (): void {
    $this->source->add(attEntry($this, 1, ['photo.png' => base64_decode(ATT_PNG)]));
    attPull();
    $id = attSubmission(1)->id;

    expect(app(FetchAttachments::class)->execute($id))->toBe(['fetched' => 0, 'rejected' => 0, 'pending' => 0]);
    (new FetchAttachmentsJob($id))->handle(app(FetchAttachments::class));

    expect($this->source->downloads)->toHaveCount(1);
});

it('uses a file already on the disk from an interrupted attempt instead of downloading it again', function (): void {
    $submission = Submission::factory()->create(['instance_id' => 'uuid:att-9', 'payload' => ['__id' => 'uuid:att-9']]);
    SubmissionAttachment::query()->create(['submission_id' => $submission->id, 'filename' => 'photo.png', 'status' => AttachmentStatus::Pending]);
    Storage::disk('attachments')->put('odk/uuid_att-9/photo.png', base64_decode(ATT_PNG));

    expect(app(FetchAttachments::class)->execute($submission->id)['fetched'])->toBe(1)
        ->and($this->source->downloads)->toBe([])
        ->and(SubmissionAttachment::query()->sole()->status)->toBe(AttachmentStatus::Fetched);
});

it('keeps a file ODK could not deliver pending for the retry, then failed, and fetch-pending catches it up', function (): void {
    $queue = Queue::getFacadeRoot();
    Queue::fake([FetchAttachmentsJob::class]);
    $this->source->add(attEntry($this, 1, ['photo.png' => base64_decode(ATT_PNG)]));
    attPull();
    $id = attSubmission(1)->id;
    $this->source->failDownload = fn (string $name): Throwable => OdkUnreachable::connection(3);

    expect(fn () => (new FetchAttachmentsJob($id))->handle(app(FetchAttachments::class)))->toThrow(OdkUnreachable::class);
    expect(SubmissionAttachment::query()->sole()->only(['status', 'reason']))->toBe(['status' => AttachmentStatus::Pending, 'reason' => 'unreachable']);

    (new FetchAttachmentsJob($id))->failed(OdkUnreachable::connection(3));
    expect(SubmissionAttachment::query()->sole()->status)->toBe(AttachmentStatus::Failed);

    $this->source->failDownload = null;
    Queue::swap($queue); // the fake also holds back dispatchSync
    $this->artisan('edqa:attachments:fetch-pending', ['--sync' => true])
        ->expectsOutputToContain('Fetched attachments for 1 submission(s)')
        ->assertSuccessful();
    expect(SubmissionAttachment::query()->sole()->only(['status', 'reason']))->toBe(['status' => AttachmentStatus::Fetched, 'reason' => null]);
});

it('queues fetch-pending on the low queue, at most --limit submissions', function (): void {
    Queue::fake([FetchAttachmentsJob::class]);
    foreach (range(1, 3) as $n) {
        SubmissionAttachment::factory()->create(['status' => 'pending']);
    }
    SubmissionAttachment::factory()->create(['status' => 'fetched']);

    $this->artisan('edqa:attachments:fetch-pending', ['--limit' => 2])->expectsOutputToContain('Queued attachment fetches for 2 submission(s)')->assertSuccessful();

    Queue::assertPushedTimes(FetchAttachmentsJob::class, 2);
    Queue::assertPushedOn('low', FetchAttachmentsJob::class);
});

/** A fetched PNG for submission 1, stored on the faked disk. */
function attServed(object $test): SubmissionAttachment
{
    $test->source->add(attEntry($test, 1, ['register.png' => base64_decode(ATT_PNG), 'trace.geojson' => '{"type":"Point","coordinates":[7.4,10.5]}']));
    attPull();

    return SubmissionAttachment::query()->where('filename', 'register.png')->sole();
}

it('streams a fetched file to an administrator, with safe headers, and audits the view', function (): void {
    $attachment = attServed($this);

    $response = $this->actingAs($this->admin)->get("/attachments/{$attachment->submission_id}/register.png");

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe(base64_decode(ATT_PNG))
        ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename=register.png')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->not->toContain('public');
    $entry = Activity::query()->forSubject($attachment)->where('event', 'viewed')->sole();
    expect($entry->causer?->is($this->admin))->toBeTrue()
        ->and($entry->properties['attributes'])->toMatchArray(['filename' => 'register.png']);
});

it('downloads a file that is not an image', function (): void {
    $attachment = attServed($this);

    $response = $this->actingAs($this->admin)->get("/attachments/{$attachment->submission_id}/trace.geojson");

    $response->assertOk()->assertHeader('Content-Type', 'application/geo+json');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=trace.geojson');
});

it('answers 404 to anything but a fetched file recorded for that submission', function (string $path): void {
    $attachment = attServed($this);
    $other = Submission::factory()->create();
    SubmissionAttachment::query()->create(['submission_id' => $attachment->submission_id, 'filename' => 'pending.png', 'status' => AttachmentStatus::Pending]);
    Storage::disk('attachments')->put('secret.txt', 'top secret');
    $id = $attachment->submission_id;

    $response = $this->actingAs($this->admin)->get(strtr($path, ['{id}' => (string) $id, '{other}' => (string) $other->id]));

    expect($response->status())->toBe(404)
        ->and($response->getContent())->not->toContain('top secret');
    expect(Activity::query()->where('event', 'viewed')->count())->toBe(0);
})->with([
    'another submission' => '/attachments/{other}/register.png',
    'encoded traversal' => '/attachments/{id}/..%2Fsecret.txt',
    'double-encoded traversal' => '/attachments/{id}/%252e%252e%252fsecret.txt',
    'dot dot' => '/attachments/{id}/..',
    'a file not recorded' => '/attachments/{id}/secret.txt',
    'not fetched yet' => '/attachments/{id}/pending.png',
    'different case' => '/attachments/{id}/REGISTER.PNG',
    'backslash' => '/attachments/{id}/..%5Csecret.txt',
    'not a number' => '/attachments/abc/register.png',
]);

it('refuses a request that is not signed in', function (): void {
    $attachment = attServed($this);

    $this->get("/attachments/{$attachment->submission_id}/register.png")->assertRedirect('/login');
    $this->getJson("/attachments/{$attachment->submission_id}/register.png")->assertUnauthorized();
    expect(Activity::query()->where('event', 'viewed')->count())->toBe(0);
});

it('answers 404 when the file is recorded but has gone from the disk', function (): void {
    $attachment = attServed($this);
    Storage::disk('attachments')->delete((string) $attachment->path);

    $this->actingAs($this->admin)->get("/attachments/{$attachment->submission_id}/register.png")->assertNotFound();
});
