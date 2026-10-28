<?php

declare(strict_types=1);

use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Models\SubmissionAttachment;
use Illuminate\Support\Facades\Storage;

// edqa:attachments:relocate (Phase 2C, ARCHITECTURE.md §5.3): fetched ODK attachments move from
// storage/app/odk/{instance}/ to storage/app/attachments/{source}/{instance}/, once.

beforeEach(function (): void {
    Storage::fake('odk');
    Storage::fake('attachments');
});

function fetchedAttachment(string $instance, string $file, ?string $path = null): SubmissionAttachment
{
    $submission = Submission::factory()->create(['instance_id' => $instance]);

    return SubmissionAttachment::factory()->for($submission)->create([
        'filename' => $file, 'status' => 'fetched', 'fetched_at' => now(), 'path' => $path ?? str_replace(':', '_', $instance)."/{$file}",
    ]);
}

it('moves each file under its source and points the row at it', function (): void {
    $attachment = fetchedAttachment('uuid:att-1', 'register.jpg');
    Storage::disk('odk')->put('uuid_att-1/register.jpg', 'jpeg bytes');

    $this->artisan('edqa:attachments:relocate')->expectsOutputToContain('moved to their source directory: 1; already there: 0')->assertSuccessful();

    expect($attachment->refresh()->path)->toBe('odk/uuid_att-1/register.jpg')
        ->and(Storage::disk('attachments')->get('odk/uuid_att-1/register.jpg'))->toBe('jpeg bytes')
        ->and(Storage::disk('odk')->exists('uuid_att-1/register.jpg'))->toBeFalse();
});

it('is idempotent, and finishes a run that stopped after the copy', function (): void {
    $done = fetchedAttachment('uuid:att-2', 'a.jpg');
    Storage::disk('odk')->put('uuid_att-2/a.jpg', 'a');
    $this->artisan('edqa:attachments:relocate')->assertSuccessful();

    // Copied by an interrupted run: the row still has the old path, the old file is still there.
    $half = fetchedAttachment('uuid:att-3', 'b.jpg');
    Storage::disk('odk')->put('uuid_att-3/b.jpg', 'b');
    Storage::disk('attachments')->put('odk/uuid_att-3/b.jpg', 'b');

    $this->artisan('edqa:attachments:relocate')->expectsOutputToContain('moved to their source directory: 1; already there: 1')->assertSuccessful();

    expect($done->refresh()->path)->toBe('odk/uuid_att-2/a.jpg')
        ->and($half->refresh()->path)->toBe('odk/uuid_att-3/b.jpg')
        ->and(Storage::disk('odk')->allFiles())->toBe([])
        ->and(Storage::disk('attachments')->allFiles())->toEqualCanonicalizing(['odk/uuid_att-2/a.jpg', 'odk/uuid_att-3/b.jpg']);
});

it('reports a row whose file is on neither disk, and leaves it as it is', function (): void {
    $lost = fetchedAttachment('uuid:att-4', 'c.jpg');
    $pending = SubmissionAttachment::factory()->create(['status' => 'pending', 'path' => null]);

    $this->artisan('edqa:attachments:relocate')->expectsOutputToContain("1 attachment(s) have no file on either disk (ids {$lost->id})")->assertSuccessful();

    expect($lost->refresh()->path)->toBe('uuid_att-4/c.jpg')
        ->and($pending->refresh()->path)->toBeNull();
});
