<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\ItemResponse;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// GET /api/facilities/{id} (ARCHITECTURE.md §8, §9.2.7): the facility detail drawer's data.

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Amina Bello']);
    $this->facility = $this->f->facilities['F1'];
    $this->assessment = Assessment::query()->where('round_id', $this->f->q2)->where('facility_id', $this->facility)->firstOrFail();

    // Availability, April: one pass, one fail, one N/A by value, one not applicable.
    foreach ([['item_1', 'yes', true], ['item_2', 'no', true], ['item_3', 'na', true], ['item_4', 'yes', false]] as [$code, $value, $applicable]) {
        Rows::itemResponse(['assessment_id' => $this->assessment->id, 'dimension' => 'availability', 'month_slot' => 1, 'item_code' => $code, 'value' => $value, 'is_applicable' => $applicable]);
    }
    Rows::itemResponse(['assessment_id' => $this->assessment->id, 'dimension' => 'validity', 'month_slot' => 3, 'item_code' => 'item_1', 'value' => 'yes']);
});

function detail(object $test, string $query = ''): array
{
    return $test->actingAs($test->admin)->getJson("/api/facilities/{$test->facility}{$query}")->assertOk()->json('data');
}

it('returns the master-list details, the latest round and its score card', function (): void {
    $data = detail($this);

    expect($data['facility'])->toMatchArray([
        'id' => $this->facility, 'code' => 'KD-F1', 'name' => 'Facility F1', 'lga' => 'Alpha', 'ward' => 'Ward F1',
        'level' => 'primary', 'level_label' => 'Primary', 'ownership' => 'public', 'ownership_label' => 'Public', 'active' => true,
    ])
        ->and($data['round'])->toMatchArray(['id' => $this->f->q2, 'label' => 'Q2 2026'])
        ->and($data['assessment'])->toMatchArray(['id' => $this->assessment->id, 'assessed_on' => '2026-04-10', 'assessor' => 'Assessor', 'status' => 'accepted'])
        // Under the round's version (v2): v1's noise scores of 1 on the same assessment are ignored.
        ->and($data['scores']['availability'])->toMatchArray(['value' => 90.0, 'display' => '90.0', 'band' => 'strong'])
        ->and($data['scores']['consistency']['display'])->toBe('80.0')
        ->and($data['scores']['validity']['display'])->toBe('70.0')
        ->and($data['scores']['overall'])->toMatchArray(['display' => '80.0', 'band' => 'acceptable', 'bandLabel' => 'Acceptable']);
});

it('groups item responses by dimension and month, each pass, fail or N/A', function (): void {
    $items = detail($this)['items'];

    expect(array_map(fn (array $g): string => "{$g['dimension']}:{$g['slot']}", $items))->toBe(['availability:1', 'validity:3'])
        ->and($items[0])->toMatchArray(['dimension_label' => 'Availability', 'month' => 'April 2026'])
        ->and($items[0]['score']['display'])->toBe('90.0')
        ->and(array_map(fn (array $i): array => [$i['code'], $i['result']], $items[0]['items']))->toBe([
            ['item_1', 'pass'], ['item_2', 'fail'], ['item_3', 'na'], ['item_4', 'na'],
        ])
        // No label for a synthetic code: null, not the lang key.
        ->and($items[0]['items'][0]['label'])->toBeNull()
        ->and($items[1]['month'])->toBe('June 2026');
});

it('gives the round-over-round overall, each round under its own version, the selected one marked', function (): void {
    $history = detail($this)['history'];

    expect(array_map(fn (array $h): array => [$h['label'], $h['overall']['display'], $h['selected']], $history))->toBe([
        ['Q1 2026', '10.0', false],
        ['Q2 2026', '80.0', true],
    ]);
});

it('reads another round when asked', function (): void {
    $data = detail($this, "?round={$this->f->q1}");

    expect($data['round']['label'])->toBe('Q1 2026')
        ->and($data['scores']['overall']['display'])->toBe('10.0')
        ->and($data['items'])->toBe([])
        ->and(array_column($data['history'], 'selected'))->toBe([true, false]);
});

it('opens on the latest assessed round the dashboard filters put in view, as the table row shows', function (): void {
    expect(detail($this, '?year=2026&quarter=1')['round']['label'])->toBe('Q1 2026')
        ->and(detail($this, '?year=2026')['round']['label'])->toBe('Q2 2026')
        // An explicit round wins over the filters.
        ->and(detail($this, "?year=2026&quarter=2&round={$this->f->q1}")['round']['label'])->toBe('Q1 2026');
});

it('shows the round in view even when the facility was not assessed in it', function (): void {
    $data = $this->actingAs($this->admin)->getJson("/api/facilities/{$this->f->facilities['F2']}?year=2026&quarter=1")->assertOk()->json('data');

    expect($data['round']['label'])->toBe('Q1 2026')
        ->and($data['assessment'])->toBeNull()
        ->and(array_column($data['history'], 'label'))->toBe(['Q2 2026']);
});

it('refuses filters that are not a year or a quarter', function (string $query): void {
    $this->actingAs($this->admin)->getJson("/api/facilities/{$this->facility}{$query}")->assertUnprocessable();
})->with(['?year=abc', '?quarter=5', '?quarter[]=1']);

it('says when the facility has no assessment in the asked round', function (): void {
    $data = detail($this, '?round='.Rows::round(['year' => 2025, 'quarter' => 4, 'window_start' => '2025-10-01', 'window_end' => '2025-12-31']));

    expect($data['round']['label'])->toBe('Q4 2025')
        ->and($data['assessment'])->toBeNull()
        ->and($data['scores']['overall'])->toMatchArray(['value' => null, 'display' => '—'])
        ->and($data['odk'])->toBe(['instance_id' => null, 'url' => null]);
});

it('lists soft flags, open and overridden, with who, when and why', function (): void {
    $this->assessment->update(['flags' => ['SCORE_JUMP', 'SLOT_ALL_NA:validity:2'], 'status' => 'flagged']);
    AuditLog::record($this->assessment, $this->admin, 'flag_overridden',
        old: ['flags' => ['ALL_PERFECT', 'SCORE_JUMP'], 'status' => 'flagged'],
        new: ['flags' => ['SCORE_JUMP'], 'status' => 'flagged'],
        reason: 'Register checked on site', extra: ['code' => 'ALL_PERFECT']);

    $flags = detail($this)['flags'];

    expect($flags)->toHaveCount(2) // SLOT_ALL_NA is a note, not a flag
        ->and($flags[0])->toMatchArray(['code' => 'SCORE_JUMP', 'label' => 'Large score change', 'overridden' => null])
        ->and($flags[1])->toMatchArray(['code' => 'ALL_PERFECT', 'label' => 'Every score perfect'])
        ->and($flags[1]['overridden'])->toMatchArray(['by' => 'Amina Bello', 'reason' => 'Register checked on site']);
});

it('shows the activity log for the facility\'s assessments: who, when, what, why; never IP or user agent', function (): void {
    $this->actingAs($this->admin);
    ItemResponse::query()->where('assessment_id', $this->assessment->id)->where('item_code', 'item_2')->firstOrFail()->update(['value' => 'yes']);
    AuditLog::record($this->assessment, $this->admin, 'flag_overridden', old: ['flags' => ['SCORE_JUMP']], new: ['flags' => []], reason: 'Explained by new staff', extra: ['code' => 'SCORE_JUMP']);
    // Another facility's entry must not appear.
    AuditLog::record(Assessment::query()->where('facility_id', $this->f->facilities['F2'])->firstOrFail(), $this->admin, 'flag_overridden', old: [], new: [], reason: 'elsewhere');

    $activity = detail($this)['activity'];

    expect($activity)->toHaveCount(2)
        ->and($activity[0])->toMatchArray(['event' => 'flag_overridden', 'actor' => 'Amina Bello', 'reason' => 'Explained by new staff'])
        ->and($activity[1])->toMatchArray(['event' => 'updated', 'actor' => 'Amina Bello', 'reason' => null])
        ->and($activity[1]['changes'])->toBe([['field' => 'value', 'old' => 'no', 'new' => 'yes']])
        ->and(json_encode($activity))->not->toContain('user_agent')->not->toContain('"ip"');
});

it('gives the ODK instance ID, and a deep link only when ODK Central is configured', function (): void {
    $instance = (string) DB::table('submissions')->where('id', $this->assessment->submission_id)->value('instance_id');

    config(['edqa.odk.base_url' => null]);
    expect(detail($this)['odk'])->toBe(['instance_id' => $instance, 'url' => null]);

    config(['edqa.odk.base_url' => 'https://central.example.org/', 'edqa.odk.project_id' => 7]);
    expect(detail($this)['odk']['url'])->toBe('https://central.example.org/#/projects/7/forms/dqa/submissions/'.rawurlencode($instance));
});

it('lists attachments (none until ODK attachments are fetched)', function (): void {
    expect(detail($this)['attachments'])->toBe([]);
});

it('is 404 for an unknown facility, and 422 for a round that does not exist', function (): void {
    $this->actingAs($this->admin)->getJson('/api/facilities/999999')->assertNotFound();
    $this->actingAs($this->admin)->getJson('/api/facilities/abc')->assertNotFound();
    $this->actingAs($this->admin)->getJson("/api/facilities/{$this->facility}?round=999999")->assertUnprocessable()->assertJsonValidationErrors('round');
});

it('is behind the sign-in', function (): void {
    $this->getJson("/api/facilities/{$this->facility}")->assertUnauthorized();
});
