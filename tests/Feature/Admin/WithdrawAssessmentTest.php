<?php

declare(strict_types=1);

use App\Domain\Assessment\Events\AssessmentStatusChanged;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Rows;

// Withdrawing an assessment whose submission was deleted at source (D-24, Phase 5 step 2), and
// restoring it. Both reasoned and audited; the round is rescored and refreshed (an event the
// reporting and scoring listeners answer). A withdrawn assessment counts nowhere
// (WithdrawnAssessmentsTest).

beforeEach(function (): void {
    $this->admin = User::factory()->create(['name' => 'Amina Bello']);
    $this->round = Rows::round(['year' => 2026, 'quarter' => 4, 'window_start' => '2026-10-01', 'window_end' => '2026-12-31']);
    $this->facility = Rows::facility();
    $this->submission = Rows::submission(['deleted_at_source' => now()->subDay(), 'status' => 'accepted']);
    $this->assessment = Rows::assessment(['round_id' => $this->round, 'facility_id' => $this->facility, 'submission_id' => $this->submission]);
});

function assessmentAction(mixed $test, string $action, int $assessmentId, ?string $reason = 'The LGA confirmed the visit never happened'): TestResponse
{
    return $test->actingAs($test->admin)->postJson("/admin/api/assessments/{$assessmentId}/{$action}", $reason === null ? [] : ['reason' => $reason]);
}

it('is behind the sign-in', function (string $action): void {
    $this->postJson("/admin/api/assessments/{$this->assessment}/{$action}", ['reason' => 'A long enough reason'])->assertUnauthorized();
})->with(['withdraw', 'restore']);

it('withdraws an assessment deleted at source: status, the deletion reviewed, audited with the reason, the round refreshed', function (): void {
    Event::fake([AssessmentStatusChanged::class]);

    assessmentAction($this, 'withdraw', $this->assessment)->assertOk()
        ->assertJsonPath('data.assessment_id', $this->assessment)
        ->assertJsonPath('data.status', 'withdrawn')
        ->assertJsonPath('data.round_id', $this->round);

    expect(DB::table('assessments')->where('id', $this->assessment)->value('status'))->toBe('withdrawn')
        ->and(DB::table('submissions')->where('id', $this->submission)->value('deletion_reviewed_by'))->toBe($this->admin->id);

    $audit = Activity::query()->where('event', 'assessment_withdrawn')->sole();
    expect($audit->causer_id)->toBe($this->admin->id)
        ->and($audit->properties['reason'])->toBe('The LGA confirmed the visit never happened')
        ->and($audit->properties['old'])->toBe(['status' => 'accepted'])
        ->and($audit->properties['attributes'])->toBe(['status' => 'withdrawn']);
    Event::assertDispatched(AssessmentStatusChanged::class, fn (AssessmentStatusChanged $e): bool => $e->assessmentId === $this->assessment && $e->roundId === $this->round);
});

it('refuses to withdraw in a closed round, twice, or when the submission is still at its source', function (Closure $setup, string $code): void {
    Event::fake([AssessmentStatusChanged::class]);
    $setup($this);
    $before = DB::table('assessments')->where('id', $this->assessment)->value('status');

    assessmentAction($this, 'withdraw', $this->assessment)->assertStatus(409)->assertJsonPath('code', $code);

    expect(DB::table('assessments')->where('id', $this->assessment)->value('status'))->toBe($before)
        ->and(Activity::query()->where('event', 'assessment_withdrawn')->count())->toBe(0);
    Event::assertNotDispatched(AssessmentStatusChanged::class);
})->with([
    'closed round' => [fn ($t) => DB::table('rounds')->where('id', $t->round)->update(['status' => 'closed', 'rule_version_id' => Rows::ruleVersion(), 'closed_at' => now()]), 'round_closed'],
    'already withdrawn' => [fn ($t) => DB::table('assessments')->where('id', $t->assessment)->update(['status' => 'withdrawn']), 'already_withdrawn'],
    'still at its source' => [fn ($t) => DB::table('submissions')->where('id', $t->submission)->update(['deleted_at_source' => null]), 'not_deleted_at_source'],
]);

it('restores a withdrawn assessment to what it was, audited with the reason', function (): void {
    Event::fake([AssessmentStatusChanged::class]);
    DB::table('assessments')->where('id', $this->assessment)->update(['status' => 'withdrawn', 'flags' => json_encode(['VISIT_TOO_SHORT'])]);

    assessmentAction($this, 'restore', $this->assessment, 'The source restored the submission')->assertOk()
        ->assertJsonPath('data.status', 'flagged'); // it still carries a soft flag

    expect(DB::table('assessments')->where('id', $this->assessment)->value('status'))->toBe('flagged');
    $audit = Activity::query()->where('event', 'assessment_restored')->sole();
    expect($audit->properties['reason'])->toBe('The source restored the submission')
        ->and($audit->properties['attributes'])->toBe(['status' => 'flagged']);
    Event::assertDispatched(AssessmentStatusChanged::class);
});

it('refuses to restore one that is not withdrawn, or once the facility has a new assessment in the round', function (Closure $setup, string $code): void {
    $setup($this);
    $before = DB::table('assessments')->where('id', $this->assessment)->value('status');

    assessmentAction($this, 'restore', $this->assessment)->assertStatus(409)->assertJsonPath('code', $code);

    expect(DB::table('assessments')->where('id', $this->assessment)->value('status'))->toBe($before)
        ->and(Activity::query()->where('event', 'assessment_restored')->count())->toBe(0);
})->with([
    'not withdrawn' => [fn () => null, 'not_withdrawn'],
    'facility assessed again' => [function ($t): void {
        DB::table('assessments')->where('id', $t->assessment)->update(['status' => 'withdrawn']);
        Rows::assessment(['round_id' => $t->round, 'facility_id' => $t->facility]);
    }, 'round_taken'],
]);

it('needs a reason of at least 10 characters', function (string $action): void {
    assessmentAction($this, $action, $this->assessment, null)->assertUnprocessable()->assertJsonValidationErrors('reason');
    assessmentAction($this, $action, $this->assessment, 'too short')->assertUnprocessable()->assertJsonValidationErrors('reason');
})->with(['withdraw', 'restore']);
