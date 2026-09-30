<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use App\Domain\Ingestion\Models\OdkAttachment;
use App\Domain\Ingestion\Models\OdkFormSync;
use App\Domain\Ingestion\Models\OdkPullRun;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Plan\Models\PlanAction;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

// SECURITY.md §4: the audit trail is the primary control.

it('records old and new values when a facility is updated', function (): void {
    $facility = Facility::factory()->create(['name' => 'PHC Kawo']);

    $facility->update(['name' => 'Primary Health Care Centre Kawo', 'is_active' => false]);

    $entry = Activity::query()->forSubject($facility)->where('event', 'updated')->sole();
    expect($entry->properties['old'])->toBe(['name' => 'PHC Kawo', 'is_active' => true])
        ->and($entry->properties['attributes'])->toBe(['name' => 'Primary Health Care Centre Kawo', 'is_active' => false]);
});

it('writes no entry for a save that changes nothing', function (): void {
    $facility = Facility::factory()->create();
    $before = Activity::query()->count();

    $facility->save();
    $facility->update(['name' => $facility->name]);

    expect(Activity::query()->count())->toBe($before);
});

it('records who made the change', function (): void {
    $admin = User::factory()->create();
    $round = Round::factory()->create();

    $this->actingAs($admin);
    $round->update(['reopened_reason' => 'Late submissions from Zaria']);

    $entry = Activity::query()->forSubject($round)->where('event', 'updated')->sole();
    expect($entry->causer?->is($admin))->toBeTrue();
});

it('records the request IP and user agent with each entry', function (): void {
    $facility = Facility::factory()->create();

    // Simulate the change happening inside an HTTP request.
    $this->app->instance('request', Request::create('/admin/facilities', 'PATCH', server: [
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Audit test)',
    ]));
    $facility->update(['name' => 'Renamed facility']);

    $entry = Activity::query()->forSubject($facility)->where('event', 'updated')->sole();
    expect($entry->properties['ip'])->toBe('203.0.113.7')
        ->and($entry->properties['user_agent'])->toBe('Mozilla/5.0 (Audit test)');
});

it('never logs passwords or two-factor secrets', function (): void {
    $user = User::factory()->create();

    // forceFill, as Fortify does: the secret is not mass-assignable.
    $user->forceFill(['password' => 'a-new-password-12', 'two_factor_secret' => 'secret', 'name' => 'Renamed'])->save();

    $entry = Activity::query()->forSubject($user)->where('event', 'updated')->sole();
    expect(array_keys($entry->properties['attributes']))->toBe(['name']);
});

it('audits exactly the models SECURITY.md §4 lists', function (string $model, bool $audited): void {
    expect(in_array(LogsActivity::class, class_uses_recursive($model), true))->toBe($audited);
})->with([
    'Assessment' => [Assessment::class, true],
    'ItemResponse (corrections)' => [ItemResponse::class, true],
    'AssessmentScore' => [AssessmentScore::class, true],
    'QuarantinedRecord' => [QuarantinedRecord::class, true],
    'Facility' => [Facility::class, true],
    'Ward' => [Ward::class, true],
    'Round' => [Round::class, true],
    'ScoringRuleVersion' => [ScoringRuleVersion::class, true],
    'PlanAction' => [PlanAction::class, true],
    'User' => [User::class, true],
    'Lga (seeded only)' => [Lga::class, false],
    'Submission (raw, immutable)' => [Submission::class, false],
    'OdkFormSync' => [OdkFormSync::class, false],
    'OdkPullRun' => [OdkPullRun::class, false],
    'OdkAttachment' => [OdkAttachment::class, false],
]);

it('logs item responses only when they are corrected, not when ingested', function (): void {
    $before = Activity::query()->where('subject_type', (new ItemResponse)->getMorphClass())->count();
    $item = ItemResponse::factory()->create();

    expect(Activity::query()->where('subject_type', $item->getMorphClass())->count())->toBe($before);

    $item->update(['value' => 'no', 'is_applicable' => true]);

    expect(Activity::query()->forSubject($item)->where('event', 'updated')->count())->toBe(1);
});
