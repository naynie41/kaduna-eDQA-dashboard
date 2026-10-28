<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Jobs\RequestRescoreJob;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ParsedSubmissionBuilder;

// The Data issues page (ARCHITECTURE.md §6, §9.3, D-57): the rule summary, the filters in the URL,
// the hard and soft tables, the record detail, the facility picker, and what the 2A.8 API refuses
// from it.

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Amina Bello']);
    $this->chikun = Lga::factory()->create(['code' => 'CHK', 'name' => 'Chikun']);
    $this->zaria = Lga::factory()->create(['code' => 'ZAR', 'name' => 'Zaria']);
    $this->facility = Facility::factory()->inWard(Ward::factory()->create(['lga_id' => $this->chikun->id]))->create(['name' => 'Kujama PHC']);
    $this->round = Round::factory()->create(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);
});

function issuesNullLga(Facility $facility, Round $round): QuarantinedRecord
{
    return quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->withLga(null)->build());
}

/** The form names the previous quarter while the visit is in this round: ROUND_WINDOW only. */
function issuesWrongQuarter(Facility $facility, Round $round): QuarantinedRecord
{
    return quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->withRoundRef($round->year, $round->quarter - 1)->build());
}

/** @return list<int> the record ids on the page for this query */
function issuesIds(mixed $test, string $query = ''): array
{
    $ids = [];
    $test->actingAs($test->admin)->get("/admin/data-issues{$query}")->assertOk()
        ->assertInertia(function (Assert $page) use (&$ids): void {
            $ids = array_column($page->toArray()['props']['records']['data'], 'id');
        });

    return $ids;
}

// ---- Guard (SECURITY.md §3)

it('is behind the sign-in, and so are its endpoints', function (): void {
    $this->get('/admin/data-issues')->assertRedirect('/login');
    $this->getJson('/api/quarantine/1')->assertUnauthorized();
    $this->getJson('/api/facility-options')->assertUnauthorized();
});

// ---- The page

it('lists every rule with severity, name, why and open count, and the open hard records', function (): void {
    $record = issuesNullLga($this->facility, $this->round);
    issuesNullLga(Facility::factory()->create(), $this->round);
    Assessment::factory()->create(['status' => 'flagged', 'flags' => ['VISIT_TOO_SHORT']]);

    $this->actingAs($this->admin)->get('/admin/data-issues')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/data-issues')
        ->missing('filters')
        ->where('issueFilters', ['tab' => 'hard', 'state' => 'open', 'rule' => null, 'lga' => null, 'round' => null])
        ->has('rules', 15)
        ->where('rules.0.code', 'UNKNOWN_FORM_VERSION')
        ->where('rules.14.code', 'RULE_ERROR')
        ->where('rules', fn ($rules): bool => collect($rules)->firstWhere('code', 'LGA_UNKNOWN')['open'] === 2
            && collect($rules)->firstWhere('code', 'LGA_UNKNOWN')['severity'] === 'hard'
            && collect($rules)->firstWhere('code', 'LGA_UNKNOWN')['why'] === __('validation_rules.LGA_UNKNOWN.why')
            && collect($rules)->firstWhere('code', 'VISIT_TOO_SHORT')['open'] === 1
            && collect($rules)->firstWhere('code', 'VISIT_TOO_SHORT')['severity'] === 'soft')
        ->has('records.data', 2)
        ->where('records.data.1.id', $record->id)
        ->where('records.data.1.facility_name', 'Kujama PHC')
        ->where('records.data.1.failures.0.code', 'LGA_UNKNOWN')
        ->where('records.data.1.current.lga_ref', null)
        ->where('records.data.1.current.facility_ref', $this->facility->code)
        ->where('records.data.1.round.id', $this->round->id)
        ->missing('records.data.1.payload')
        ->where('lgas', fn ($lgas): bool => collect($lgas)->contains(['code' => 'CHK', 'name' => 'Chikun']) && collect($lgas)->pluck('name')->all() === collect($lgas)->pluck('name')->sort()->values()->all())
        ->where('correctable', ['lga_ref', 'ward_ref', 'facility_ref', 'raw_start', 'raw_end', 'round_year', 'round_quarter'])
        ->has('translations.admin.issues'));
});

it('filters the hard tab by rule, LGA and round', function (): void {
    $nullLga = issuesNullLga($this->facility, $this->round);
    $window = issuesWrongQuarter($this->facility, $this->round);
    $zariaFacility = Facility::factory()->inWard(Ward::factory()->create(['lga_id' => $this->zaria->id]))->create();
    $zaria = issuesWrongQuarter($zariaFacility, $this->round);
    $other = Round::factory()->create(['year' => 2025, 'quarter' => 4, 'window_start' => '2025-10-01', 'window_end' => '2025-12-31']);
    $elsewhere = issuesNullLga(Facility::factory()->create(), $other);

    expect(issuesIds($this, '?rule=ROUND_WINDOW'))->toEqualCanonicalizing([$window->id, $zaria->id])
        ->and(issuesIds($this, '?lga=ZAR'))->toBe([$zaria->id])
        ->and(issuesIds($this, "?round={$other->id}"))->toBe([$elsewhere->id])
        ->and(issuesIds($this, "?rule=LGA_UNKNOWN&round={$this->round->id}"))->toBe([$nullLga->id]);
});

it('filters by status: open, resolved (reprocessed), rejected and superseded', function (): void {
    $open = issuesNullLga($this->facility, $this->round);
    $rejected = issuesNullLga(Facility::factory()->create(), $this->round);
    $fixed = issuesNullLga(Facility::factory()->inWard(Ward::factory()->create(['lga_id' => $this->chikun->id]))->create(), $this->round);

    $this->actingAs($this->admin)->postJson("/api/quarantine/{$rejected->id}/resolve", ['action' => 'reject', 'reason' => 'Training submission, not real'])->assertOk();
    $this->actingAs($this->admin)->postJson("/api/quarantine/{$fixed->id}/resolve", ['action' => 'correct', 'corrections' => ['lga_ref' => 'CHK'], 'reason' => 'Assessor confirmed the LGA by phone'])
        ->assertOk()->assertJsonPath('data.status', 'accepted');

    expect(issuesIds($this))->toBe([$open->id])
        ->and(issuesIds($this, '?state=rejected'))->toBe([$rejected->id])
        ->and(issuesIds($this, '?state=resolved'))->toBe([$fixed->id])
        ->and(issuesIds($this, '?state=superseded'))->toBe([]);
});

it('lists flagged accepted assessments on the soft tab, by rule and LGA, with soft codes only', function (): void {
    $short = Assessment::factory()->create(['status' => 'flagged', 'flags' => ['VISIT_TOO_SHORT', 'ALL_PERFECT'], 'round_id' => $this->round->id, 'facility_id' => $this->facility->id]);
    $volume = Assessment::factory()->create(['status' => 'flagged', 'flags' => ['ASSESSOR_VOLUME']]);
    Assessment::factory()->create(['status' => 'accepted', 'flags' => []]);

    expect(issuesIds($this, '?tab=soft'))->toEqualCanonicalizing([$short->id, $volume->id])
        ->and(issuesIds($this, '?tab=soft&rule=ASSESSOR_VOLUME'))->toBe([$volume->id])
        ->and(issuesIds($this, '?tab=soft&lga=CHK'))->toBe([$short->id]);

    $this->actingAs($this->admin)->get('/admin/data-issues?tab=soft&rule=VISIT_TOO_SHORT')->assertInertia(fn (Assert $page) => $page
        ->where('records.data.0.facility.name', 'Kujama PHC')
        ->where('records.data.0.lga.code', 'CHK')
        ->where('records.data.0.flags', fn ($flags): bool => collect($flags)->pluck('code')->sort()->values()->all() === ['ALL_PERFECT', 'VISIT_TOO_SHORT']));
});

it('refuses an unknown filter value', function (string $query, string $field): void {
    $this->actingAs($this->admin)->get("/admin/data-issues?{$query}")->assertSessionHasErrors($field);
})->with([
    ['tab=medium', 'tab'],
    ['state=pending', 'state'],
    ['rule=NOT_A_RULE', 'rule'],
    ['lga=XYZ', 'lga'],
    ['round=999999', 'round'],
]);

it('links each record to ODK Central only when the base URL is configured', function (): void {
    $record = issuesNullLga($this->facility, $this->round);

    config(['edqa.sources.odk.url' => null]);
    $this->actingAs($this->admin)->get('/admin/data-issues')->assertInertia(fn (Assert $page) => $page->where('records.data.0.odk_url', null));

    config(['edqa.sources.odk.url' => 'https://central.example.org/', 'edqa.sources.odk.project_id' => '7']);
    $this->actingAs($this->admin)->get('/admin/data-issues')->assertInertia(fn (Assert $page) => $page
        ->where('records.data.0.odk_url', 'https://central.example.org/#/projects/7/forms/'.rawurlencode((string) $record->submission->form_ref).'/submissions/'.rawurlencode($record->instance_id)));
});

// ---- GET /api/quarantine/{record}

it('gives one record in full: failures, parsed values, the raw payload and its history', function (): void {
    $record = issuesNullLga($this->facility, $this->round);
    $this->actingAs($this->admin)->postJson("/api/quarantine/{$record->id}/resolve", ['action' => 'reject', 'reason' => 'Training submission, not real'])->assertOk();

    $this->actingAs($this->admin)->getJson("/api/quarantine/{$record->id}")->assertOk()
        ->assertJsonPath('data.id', $record->id)
        ->assertJsonPath('data.failures.0.code', 'LGA_UNKNOWN')
        ->assertJsonPath('data.parsed.facility_ref', $this->facility->code)
        ->assertJsonPath('data.payload', $record->submission->payload)
        ->assertJsonPath('data.history.0.by', 'Amina Bello')
        ->assertJsonPath('data.history.0.reason', 'Training submission, not real');
});

it('answers 404 for a record that does not exist', function (): void {
    $this->actingAs($this->admin)->getJson('/api/quarantine/999999')->assertNotFound();
});

// ---- GET /api/facility-options

it('offers active facilities of the chosen LGA, searched by code or name, with wildcards escaped', function (): void {
    $ward = Ward::factory()->create(['lga_id' => $this->chikun->id]);
    Facility::factory()->inWard($ward)->create(['code' => 'CHK-0100', 'name' => 'Narayi 100% Clinic']);
    Facility::factory()->inWard($ward)->create(['code' => 'CHK-0101', 'name' => 'Narayi Health Post']);
    Facility::factory()->inWard($ward)->create(['code' => 'CHK-0102', 'name' => 'Narayi Closed', 'is_active' => false]);
    Facility::factory()->inWard(Ward::factory()->create(['lga_id' => $this->zaria->id]))->create(['code' => 'ZAR-0100', 'name' => 'Narayi in Zaria']);

    $codes = fn (string $query): array => array_column($this->actingAs($this->admin)->getJson("/api/facility-options?{$query}")->assertOk()->json('data'), 'code');

    expect($codes('lga=CHK&q=narayi'))->toEqualCanonicalizing(['CHK-0100', 'CHK-0101'])
        ->and($codes('lga=CHK&q=0101'))->toBe(['CHK-0101'])
        ->and($codes('lga=CHK&q=100%25'))->toBe(['CHK-0100'])
        ->and($codes('lga=CHK&q=%25'))->toBe(['CHK-0100'])
        ->and($codes('q=narayi'))->toEqualCanonicalizing(['CHK-0100', 'CHK-0101', 'ZAR-0100']);
});

// ---- What the page sends, refused by the API

it('refuses to correct a field outside the whitelist and leaves the record untouched', function (string $field, mixed $value): void {
    $record = issuesNullLga($this->facility, $this->round);
    $audits = Activity::query()->count();

    $this->actingAs($this->admin)->postJson("/api/quarantine/{$record->id}/resolve", [
        'action' => 'correct', 'corrections' => ['lga_ref' => 'CHK', $field => $value], 'reason' => 'Assessor confirmed the LGA by phone',
    ])->assertStatus(409)->assertJsonPath('code', 'field_not_correctable');

    expect($record->refresh()->status->value)->toBe('open')
        ->and($record->corrections)->toBe([])
        ->and(Activity::query()->count())->toBe($audits)
        ->and(Assessment::query()->count())->toBe(0);
})->with([
    'a score' => ['availability_m1', 100],
    'the assessor' => ['assessor_name', 'Someone else'],
    'the parsed start' => ['started_at', '2026-04-14T09:00:00+01:00'],
    'the instance id' => ['instance_id', 'uuid:other'],
]);

it('refuses a bulk correction of a field outside the whitelist', function (): void {
    $ids = [issuesWrongQuarter($this->facility, $this->round)->id, issuesWrongQuarter(Facility::factory()->create(), $this->round)->id];

    $this->actingAs($this->admin)->postJson('/api/quarantine/bulk-resolve', [
        'ids' => $ids, 'action' => 'correct', 'corrections' => ['items' => []], 'reason' => 'Changing the answers in bulk',
    ])->assertStatus(409)->assertJsonPath('code', 'field_not_correctable');

    expect(QuarantinedRecord::query()->whereIn('id', $ids)->where('status', 'open')->count())->toBe(2);
});

it('refuses bulk across different rules, and across one rule against two', function (): void {
    $window = issuesWrongQuarter($this->facility, $this->round)->id;
    $nullLga = issuesNullLga(Facility::factory()->create(), $this->round)->id;
    $both = quarantineThrough(ParsedSubmissionBuilder::new()->forFacility(Facility::factory()->create())->inRound($this->round)->withLga(null)->withRoundRef($this->round->year, $this->round->quarter - 1)->build())->id;

    foreach ([[$window, $nullLga], [$nullLga, $both]] as $ids) {
        $this->actingAs($this->admin)->postJson('/api/quarantine/bulk-resolve', ['ids' => $ids, 'action' => 'reject', 'reason' => 'Training submissions'])
            ->assertStatus(409)->assertJsonPath('code', 'mixed_rules');
    }
    expect(QuarantinedRecord::query()->where('status', 'open')->count())->toBe(3);
});

it('bulk-corrects the same field on records failing the same rule, and they are accepted', function (): void {
    $ids = [
        issuesWrongQuarter($this->facility, $this->round)->id,
        issuesWrongQuarter(Facility::factory()->inWard(Ward::factory()->create(['lga_id' => $this->chikun->id]))->create(), $this->round)->id,
    ];

    $this->actingAs($this->admin)->postJson('/api/quarantine/bulk-resolve', [
        'ids' => $ids, 'action' => 'correct', 'corrections' => ['round_quarter' => $this->round->quarter], 'reason' => 'Form defaulted to last quarter',
    ])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'accepted')->assertJsonPath('data.1.record_status', 'resolved');

    expect(Assessment::query()->count())->toBe(2);
});

// ---- After a resolution

it('names the round whose rescore is queued, and queues it', function (): void {
    $record = issuesNullLga($this->facility, $this->round);
    Queue::fake([RequestRescoreJob::class]);

    $this->actingAs($this->admin)->postJson("/api/quarantine/{$record->id}/resolve", [
        'action' => 'correct', 'corrections' => ['lga_ref' => 'CHK'], 'reason' => 'Assessor confirmed the LGA by phone',
    ])->assertOk()->assertJsonPath('data.rescore_round', ['id' => $this->round->id, 'year' => 2026, 'quarter' => 2]);

    Queue::assertPushed(RequestRescoreJob::class, fn (RequestRescoreJob $job): bool => $job->roundId === $this->round->id);
});

it('names no rescore while the record is still failing', function (): void {
    $record = issuesNullLga($this->facility, $this->round);

    $this->actingAs($this->admin)->postJson("/api/quarantine/{$record->id}/resolve", [
        'action' => 'correct', 'corrections' => ['lga_ref' => 'ZAR'], 'reason' => 'Trying the neighbouring LGA',
    ])->assertOk()->assertJsonPath('data.record_status', 'open')->assertJsonPath('data.rescore_round', null)
        ->assertJsonPath('data.failures.0.code', 'FACILITY_LGA_MISMATCH');
});
