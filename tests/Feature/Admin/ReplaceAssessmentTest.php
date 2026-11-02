<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ParsedSubmissionBuilder;

// "Use this one instead" on a cross-source duplicate (§5.8, Phase 5 step 2): the quarantined
// submission replaces the accepted assessment's items and scores (the same assessment row), the
// old submission is superseded, the record resolves, the round is rescored. Reasoned, audited
// with both instance IDs; refused in a closed round (D-18) and for anything but a duplicate.

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Amina Bello']);
    $this->round = Round::factory()->create(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);
    $this->facility = Facility::factory()->create();

    // Accepted first: every slot 75. A second submission of the same visit, with one more item
    // passing, quarantines as DUPLICATE_ASSESSMENT. Both are ODK rows here (the action does not
    // depend on the source); the cross-source case runs end to end against both mocks.
    $first = ParsedSubmissionBuilder::new()->forFacility($this->facility)->inRound($this->round)->build();
    $second = ParsedSubmissionBuilder::new()->forFacility($this->facility)->inRound($this->round)
        ->withItemValue(Dimension::Availability, 1, 'reporting_rate', 'pass')->build();
    app(ProcessParsedBatch::class)->execute([[submissionRow($first), $first]]);
    app(ProcessParsedBatch::class)->execute([[submissionRow($second), $second]]);
    $this->first = $first;
    $this->second = $second;
    $this->assessment = Assessment::query()->where('facility_id', $this->facility->id)->where('round_id', $this->round->id)->sole();
    $this->record = QuarantinedRecord::query()->where('instance_id', $second->instanceId)->sole();
});

function replaceWith(mixed $test, QuarantinedRecord $record, ?string $reason = 'The Kobo copy is the supervisor-checked one'): TestResponse
{
    return $test->actingAs($test->admin)->postJson("/api/quarantine/{$record->id}/resolve", ['action' => 'replace', ...($reason === null ? [] : ['reason' => $reason])]);
}

it('holds the second copy as a duplicate to begin with', function (): void {
    expect(array_column($this->record->failures, 'code'))->toBe(['DUPLICATE_ASSESSMENT']);
});

it('replaces the accepted assessment from this submission: same row, its items and scores, the old submission superseded, audited with both IDs', function (): void {
    Event::fake([QuarantineResolved::class]);
    $before = (float) DB::table('assessment_scores')->where(['assessment_id' => $this->assessment->id, 'dimension' => 'availability', 'month_slot' => 1])->value('score');

    replaceWith($this, $this->record)->assertOk()->assertJsonPath('data.record_status', 'resolved');

    $assessment = $this->assessment->refresh();
    $newSubmission = (int) DB::table('submissions')->where('instance_id', $this->second->instanceId)->value('id');
    expect($assessment->submission_id)->toBe($newSubmission)
        ->and(Assessment::query()->where('facility_id', $this->facility->id)->where('round_id', $this->round->id)->count())->toBe(1)
        ->and(DB::table('submissions')->where('instance_id', $this->first->instanceId)->value('status'))->toBe('superseded')
        ->and(DB::table('submissions')->where('instance_id', $this->first->instanceId)->value('superseded_by_id'))->toBe($newSubmission)
        ->and(DB::table('item_responses')->where(['assessment_id' => $assessment->id, 'dimension' => 'availability', 'month_slot' => 1, 'item_code' => 'reporting_rate'])->value('value'))->toBe('pass')
        ->and((float) DB::table('assessment_scores')->where(['assessment_id' => $assessment->id, 'dimension' => 'availability', 'month_slot' => 1])->value('score'))->toBeGreaterThan($before)
        ->and($this->record->refresh()->status->value)->toBe('resolved');

    $audit = Activity::query()->where('event', 'assessment_replaced')->sole();
    expect($audit->causer_id)->toBe($this->admin->id)
        ->and($audit->properties['reason'])->toBe('The Kobo copy is the supervisor-checked one')
        ->and($audit->properties['replaced_instance_id'])->toBe($this->first->instanceId)
        ->and($audit->properties['instance_id'])->toBe($this->second->instanceId)
        ->and($audit->properties['assessment_id'])->toBe($assessment->id);
    Event::assertDispatched(QuarantineResolved::class, fn (QuarantineResolved $e): bool => $e->quarantinedRecordId === $this->record->id && $e->roundId === $this->round->id);
});

it('refuses in a closed round, and changes nothing', function (): void {
    $this->round->update(['status' => 'closed', 'rule_version_id' => ScoringRuleVersion::query()->value('id'), 'closed_at' => now()]);

    replaceWith($this, $this->record)->assertStatus(409)->assertJsonPath('code', 'round_closed');

    expect($this->assessment->refresh()->submission_id)->toBe((int) DB::table('submissions')->where('instance_id', $this->first->instanceId)->value('id'))
        ->and($this->record->refresh()->status->value)->toBe('open')
        ->and(Activity::query()->where('event', 'assessment_replaced')->count())->toBe(0);
});

it('refuses a record that is not a duplicate, or no longer open', function (Closure $setup, string $code): void {
    $record = $setup($this);

    replaceWith($this, $record)->assertStatus(409)->assertJsonPath('code', $code);
    expect(Activity::query()->where('event', 'assessment_replaced')->count())->toBe(0);
})->with([
    'another rule' => [function ($t): QuarantinedRecord {
        $parsed = ParsedSubmissionBuilder::new()->forFacility(Facility::factory()->create())->inRound($t->round)->withFacilityRef('NO-SUCH-FACILITY')->build();
        app(ProcessParsedBatch::class)->execute([[submissionRow($parsed), $parsed]]);

        return QuarantinedRecord::query()->where('instance_id', $parsed->instanceId)->sole();
    }, 'not_a_duplicate'],
    'already resolved' => [function ($t): QuarantinedRecord {
        $t->record->update(['status' => 'resolved', 'resolution' => 'rejected', 'resolved_at' => now(), 'resolved_by' => $t->admin->id]);

        return $t->record;
    }, 'already_resolved'],
]);

it('needs a reason', function (): void {
    replaceWith($this, $this->record, null)->assertUnprocessable()->assertJsonValidationErrors('reason');
    replaceWith($this, $this->record, 'too short')->assertUnprocessable()->assertJsonValidationErrors('reason');
});
