<?php

declare(strict_types=1);

use App\Domain\Ingestion\Events\DeletionReviewed;
use App\Domain\Ingestion\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Rows;

// Reviewing a deletion at source (D-62, ARCHITECTURE.md §9.3): Reconcile lists a submission its
// source no longer returns; nothing is removed from the portal. An administrator marks it reviewed
// with a reason, which takes it out of the open issues on the Data issues badge. Audited.

beforeEach(function (): void {
    $this->admin = User::factory()->create(['name' => 'Amina Bello']);
});

function reviewDeletion(mixed $test, int $submissionId, ?string $reason = 'Confirmed with the LGA M&E officer'): TestResponse
{
    return $test->actingAs($test->admin)->postJson("/admin/api/submissions/{$submissionId}/deletion-review", $reason === null ? [] : ['reason' => $reason]);
}

it('is behind the sign-in', function (): void {
    $this->postJson('/admin/api/submissions/1/deletion-review', ['reason' => 'A long enough reason'])->assertUnauthorized();
});

it('marks a deletion at source reviewed, with who and when, audits it with the reason, and says so', function (): void {
    Event::fake([DeletionReviewed::class]);
    $this->freezeSecond();
    $id = Rows::submission(['deleted_at_source' => now()->subDay()]);

    reviewDeletion($this, $id)->assertOk()
        ->assertJsonPath('data.submission_id', $id)
        ->assertJsonPath('data.reviewed_at', now()->toIso8601String());

    $row = DB::table('submissions')->where('id', $id)->first();
    expect($row->deletion_reviewed_by)->toBe($this->admin->id)
        ->and($row->deleted_at_source)->not->toBeNull(); // nothing is removed (D-62)

    $audit = Activity::query()->where('event', 'deletion_reviewed')->sole();
    expect($audit->subject_id)->toBe($id)
        ->and($audit->subject_type)->toBe((new Submission)->getMorphClass())
        ->and($audit->causer_id)->toBe($this->admin->id)
        ->and($audit->properties['reason'])->toBe('Confirmed with the LGA M&E officer')
        ->and($audit->properties['old'])->toBe(['deletion_reviewed_at' => null]);

    Event::assertDispatched(DeletionReviewed::class, fn (DeletionReviewed $e): bool => $e->submissionId === $id && $e->source === 'odk');
});

it('refuses a submission still at its source, and one already reviewed, changing nothing', function (Closure $make, string $code): void {
    Event::fake([DeletionReviewed::class]);
    $id = $make($this->admin);
    $before = DB::table('submissions')->where('id', $id)->value('deletion_reviewed_at');

    reviewDeletion($this, $id)->assertStatus(409)->assertJsonPath('code', $code);

    expect(DB::table('submissions')->where('id', $id)->value('deletion_reviewed_at'))->toBe($before)
        ->and(Activity::query()->where('event', 'deletion_reviewed')->count())->toBe(0);
    Event::assertNotDispatched(DeletionReviewed::class);
})->with([
    'still at its source' => [fn (): int => Rows::submission(), 'not_deleted'],
    'already reviewed' => [fn (User $admin): int => Rows::submission(['deleted_at_source' => now()->subDay(), 'deletion_reviewed_at' => now()->subHour(), 'deletion_reviewed_by' => $admin->id]), 'already_reviewed'],
]);

it('needs a reason of at least 10 characters, and an existing submission', function (): void {
    $id = Rows::submission(['deleted_at_source' => now()->subDay()]);

    reviewDeletion($this, $id, null)->assertUnprocessable()->assertJsonValidationErrors('reason');
    reviewDeletion($this, $id, 'too short')->assertUnprocessable()->assertJsonValidationErrors('reason');
    reviewDeletion($this, 999999)->assertNotFound();
    expect(DB::table('submissions')->where('id', $id)->value('deletion_reviewed_at'))->toBeNull();
});
