<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;
use Tests\Support\ParsedSubmissionBuilder;

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->chikun = Lga::factory()->create(['code' => 'CHK', 'name' => 'Chikun']);
    $this->facility = Facility::factory()->inWard(Ward::factory()->create(['lga_id' => $this->chikun->id]))->create();
    $this->round = Round::factory()->create();
});

function nullLgaRecord(Facility $facility, Round $round, ?string $lgaRef = null): QuarantinedRecord
{
    return quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->withLga($lgaRef)->build());
}

// ---- Authentication (SECURITY.md §3)

it('refuses every quarantine endpoint to an unauthenticated user', function (string $method, string $uri): void {
    $this->json($method, $uri, ['reason' => 'A long enough reason'])->assertUnauthorized();
})->with([
    ['GET', '/api/quarantine'],
    ['POST', '/api/quarantine/1/resolve'],
    ['POST', '/api/quarantine/bulk-resolve'],
    ['POST', '/api/assessments/1/flags/VISIT_TOO_SHORT/override'],
]);

// ---- GET /api/quarantine

it('lists records flat, with every failure named and explained', function (): void {
    $record = nullLgaRecord($this->facility, $this->round);

    $this->actingAs($this->admin)->getJson('/api/quarantine')
        ->assertOk()
        ->assertJsonPath('data.0.id', $record->id)
        ->assertJsonPath('data.0.instance_id', $record->instance_id)
        ->assertJsonPath('data.0.facility_ref', $this->facility->code)
        ->assertJsonPath('data.0.status', 'open')
        ->assertJsonPath('data.0.failures.0.code', 'LGA_UNKNOWN')
        ->assertJsonPath('data.0.failures.0.name', 'Unknown LGA')
        ->assertJsonPath('data.0.failures.0.detail', 'lga was blank')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonMissingPath('data.0.payload');
});

it('groups records by rule code with a count each', function (): void {
    nullLgaRecord($this->facility, $this->round);
    nullLgaRecord(Facility::factory()->create(), $this->round);
    quarantineThrough(ParsedSubmissionBuilder::new()->withWard('Quarterly')->withLga(null)->build());

    $this->actingAs($this->admin)->getJson('/api/quarantine?group=rule')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'LGA_UNKNOWN')
        ->assertJsonPath('data.0.count', 3)
        ->assertJsonPath('data.1.code', 'COLUMN_DRIFT')
        ->assertJsonPath('data.1.count', 1)
        ->assertJsonPath('data.1.name', 'Shifted columns');
});

it('filters by rule, round, LGA and status', function (): void {
    $chikunRecord = nullLgaRecord($this->facility, $this->round, 'Kaduna Central');
    $otherRound = Round::factory()->create();
    $otherRecord = nullLgaRecord(Facility::factory()->create(), $otherRound);
    $drift = quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($this->facility)->inRound($this->round)->withWard('Quarterly')->withLga('chikun')->build());

    $ids = fn (string $query): array => array_column($this->actingAs($this->admin)->getJson("/api/quarantine?{$query}")->assertOk()->json('data'), 'id');

    expect($ids('rule=COLUMN_DRIFT'))->toBe([$drift->id])
        ->and($ids("round={$otherRound->id}"))->toBe([$otherRecord->id])
        ->and($ids('lga=CHK'))->toBe([$drift->id])
        ->and($ids('status=resolved'))->toBe([])
        ->and(count($ids('status=open')))->toBe(3)
        ->and($chikunRecord->id)->not->toBeIn($ids('lga=CHK'));
});

it('rejects an unknown filter value with a 422', function (string $query): void {
    $this->actingAs($this->admin)->getJson("/api/quarantine?{$query}")->assertUnprocessable();
})->with(['status=pending', 'group=lga', 'rule=NOT_A_RULE', 'round=abc']);

// ---- POST /api/quarantine/{id}/resolve

it('corrects and reprocesses a record', function (): void {
    $record = nullLgaRecord($this->facility, $this->round);

    $this->actingAs($this->admin)->postJson("/api/quarantine/{$record->id}/resolve", [
        'action' => 'correct', 'corrections' => ['lga_ref' => 'CHK'], 'reason' => 'Assessor confirmed the LGA by phone',
    ])->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.assessment_id', Assessment::query()->sole()->id);

    expect($record->refresh()->resolution)->toBe(QuarantineResolution::Reprocessed);
});

it('rejects a record', function (): void {
    $record = nullLgaRecord($this->facility, $this->round);

    $this->actingAs($this->admin)->postJson("/api/quarantine/{$record->id}/resolve", ['action' => 'reject', 'reason' => 'Training submission, not real'])
        ->assertOk()->assertJsonPath('data.resolution', 'rejected');
});

it('validates the request before any action runs', function (array $body, string $field): void {
    $record = nullLgaRecord($this->facility, $this->round);

    $this->actingAs($this->admin)->postJson("/api/quarantine/{$record->id}/resolve", $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($record->refresh()->status->value)->toBe('open');
})->with([
    'no reason' => [['action' => 'reject'], 'reason'],
    'short reason' => [['action' => 'reject', 'reason' => 'short'], 'reason'],
    'unknown action' => [['action' => 'delete', 'reason' => 'A long enough reason'], 'action'],
    'override belongs to assessments' => [['action' => 'override', 'reason' => 'A long enough reason'], 'action'],
    'correct without corrections' => [['action' => 'correct', 'reason' => 'A long enough reason'], 'corrections'],
]);

it('answers a refused correction with 409 and the domain code', function (): void {
    $record = nullLgaRecord($this->facility, $this->round);

    $this->actingAs($this->admin)->postJson("/api/quarantine/{$record->id}/resolve", [
        'action' => 'correct', 'corrections' => ['items' => []], 'reason' => 'Trying to change the answers',
    ])->assertStatus(409)->assertJson(['code' => 'field_not_correctable', 'message' => __('quarantine.errors.field_not_correctable', ['field' => 'items'])]);
});

// ---- POST /api/quarantine/bulk-resolve

it('bulk-resolves records that fail the same rule', function (): void {
    $ids = [nullLgaRecord($this->facility, $this->round)->id, nullLgaRecord(Facility::factory()->create(), $this->round)->id];

    $this->actingAs($this->admin)->postJson('/api/quarantine/bulk-resolve', ['ids' => $ids, 'action' => 'reject', 'reason' => 'Training submissions'])
        ->assertOk()->assertJsonCount(2, 'data');
});

it('answers a mixed-rule bulk request with 409', function (): void {
    $ids = [nullLgaRecord($this->facility, $this->round)->id, quarantineThrough(ParsedSubmissionBuilder::new()->withWard('Quarterly')->build())->id];

    $this->actingAs($this->admin)->postJson('/api/quarantine/bulk-resolve', ['ids' => $ids, 'action' => 'reject', 'reason' => 'Training submissions'])
        ->assertStatus(409)->assertJsonPath('code', 'mixed_rules');
});

// ---- POST /api/assessments/{id}/flags/{code}/override

it('overrides a soft flag on an assessment', function (): void {
    $assessment = Assessment::factory()->create(['status' => 'flagged', 'flags' => ['VISIT_TOO_SHORT']]);

    $this->actingAs($this->admin)->postJson("/api/assessments/{$assessment->id}/flags/VISIT_TOO_SHORT/override", ['reason' => 'Visit time confirmed with the supervisor'])
        ->assertOk()->assertJsonPath('data.flags', [])->assertJsonPath('data.status', 'accepted');
});
