<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\FieldMap;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use Database\Seeders\ScoringRuleVersionSeeder;

// The real KoboToolbox form read through item rules (ARCHITECTURE.md §5.5, D-65–D-71, D-74): each
// rule turns one question, or a group of questions, into pass / fail / N/A items, as the form's own
// score formulas count them. A small hand-written rule set here, and the real generated one below.

const KOBO_TEST_VERSION = 'vKoboRulesTest000000001';

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class); // its choice map: yes → pass, no → fail, na → na
    CurrentRuleVersion::forget();
    $maps = config('edqa_field_maps');
    $maps['maps']['kobo-test'] = [
        'facility_code' => 'fac.hf', 'lga_code' => 'fac.lga', 'ward_code' => 'fac.ward',
        'round_year' => 'fac.year', 'round_quarter' => 'review_quarter', 'round_quarter_format' => 'q',
        'review_month' => 'review_month', 'started_at' => 'start', 'ended_at' => 'end', 'assessor' => 'who.name',
        'item_rules' => 'kobo-test',
        'visit_mode' => ['path' => 'period_visit', 'monthly' => 'monthly_dqa', 'quarterly' => 'quarterly_dqa'],
        'out_of_scope' => [
            'community_visit' => ['path' => 'field_visit_type', 'value' => 'community_visit'],
            'ddu' => ['path' => 'ddu_dqa', 'value' => 'ddu'],
        ],
    ];
    $maps['sources']['kobo'][KOBO_TEST_VERSION] = 'kobo-test';
    config(['edqa_field_maps' => $maps, 'edqa_item_rules.kobo-test' => [
        // Monthly: one of each kind, one gated by a service.
        ['dimension' => 'availability', 'mode' => 'monthly', 'slot' => null, 'quarter' => null, 'kind' => 'choice', 'code' => 'm_a1', 'paths' => ['mon.m_a1'], 'service' => null],
        ['dimension' => 'availability', 'mode' => 'monthly', 'slot' => null, 'quarter' => null, 'kind' => 'choice', 'code' => 'm_a2', 'paths' => ['mon.m_a2'], 'service' => 'services.anc'],
        ['dimension' => 'consistency', 'mode' => 'monthly', 'slot' => null, 'quarter' => null, 'kind' => 'count3', 'code' => 'm_c1', 'paths' => ['mon.m_c1'], 'service' => null],
        ['dimension' => 'validity', 'mode' => 'monthly', 'slot' => null, 'quarter' => null, 'kind' => 'match', 'code' => 'm_v1', 'paths' => ['mon.m_dhis_1', 'mon.m_msf_1', 'mon.m_reg_1'], 'service' => null],
        // Quarterly: Q1's January and March, and an item of Q3 that a Q1 visit never reads.
        ['dimension' => 'availability', 'mode' => 'quarterly', 'slot' => 1, 'quarter' => 1, 'kind' => 'choice', 'code' => 'q_a_jan', 'paths' => ['qtr.q_a_jan'], 'service' => null],
        ['dimension' => 'consistency', 'mode' => 'quarterly', 'slot' => 3, 'quarter' => 1, 'kind' => 'count3', 'code' => 'q_c_mar', 'paths' => ['qtr.q_c_mar'], 'service' => null],
        ['dimension' => 'validity', 'mode' => 'quarterly', 'slot' => 1, 'quarter' => 1, 'kind' => 'match', 'code' => 'q_v_jan', 'paths' => ['qtr.q_dhis_1', 'qtr.q_reg_1'], 'service' => null],
        ['dimension' => 'availability', 'mode' => 'quarterly', 'slot' => 1, 'quarter' => 3, 'kind' => 'choice', 'code' => 'q_a_jul', 'paths' => ['qtr.q_a_jul'], 'service' => null],
    ]]);
});

/** A Kobo data row of the test form: a monthly visit by default. */
function koboRulesRow(array $answers = []): array
{
    return [
        '_id' => 7001, '__version__' => KOBO_TEST_VERSION, 'meta/instanceID' => 'uuid:kobo-rules-1', '_submission_time' => '2026-08-04T10:00:00',
        'start' => '2026-08-04T09:00:00.000+01:00', 'end' => '2026-08-04T10:30:00.000+01:00', 'deviceid' => 'collect:x',
        'field_visit_type' => 'facility_visit', 'ddu_dqa' => 'dqa', 'period_visit' => 'monthly_dqa', 'review_month' => 'august',
        'fac/lga' => 'CHK', 'fac/ward' => 'CHK-KUJ', 'fac/hf' => 'kujama_phc_1', 'fac/year' => '2026', 'who/name' => 'Hauwa Ibrahim',
        'services/anc' => 'yes',
        'mon/m_a1' => 'yes', 'mon/m_a2' => 'no', 'mon/m_c1' => '2', 'mon/m_dhis_1' => '40', 'mon/m_msf_1' => '40', 'mon/m_reg_1' => '40',
        ...$answers,
    ];
}

/** @return array<string, list<string>> item code => its values, in order */
function koboValues(ParsedSubmission $parsed): array
{
    $values = [];
    foreach ($parsed->items as $item) {
        $values[$item->itemCode][] = $item->value;
    }

    return $values;
}

it('reads a monthly visit: its month is its one slot, the round its quarter (D-70, D-71)', function (): void {
    $parsed = koboParse(koboRulesRow());

    expect($parsed->formVersionKnown)->toBeTrue()
        ->and($parsed->monthSlots)->toBe([2]) // August: the second month of Q3
        ->and([$parsed->roundYear, $parsed->roundQuarter])->toBe([2026, 3])
        ->and(collect($parsed->items)->pluck('monthSlot')->unique()->values()->all())->toBe([2])
        ->and($parsed->facilityRef)->toBe('kujama_phc_1')
        ->and($parsed->assessorName)->toBe('Hauwa Ibrahim')
        ->and($parsed->outOfScope)->toBeNull();
});

it('scores availability through the choice map: yes passes, no fails (D-66)', function (): void {
    expect(koboValues(koboParse(koboRulesRow()))['m_a1'])->toBe(['pass'])
        ->and(koboValues(koboParse(koboRulesRow()))['m_a2'])->toBe(['fail']);
});

it('makes an availability item N/A when it is unanswered, or its service is not offered (D-66)', function (array $answers): void {
    $item = collect(koboParse(koboRulesRow($answers))->items)->first(fn (ItemResponseData $i): bool => $i->itemCode === 'm_a2');

    expect($item?->value)->toBe('na')->and($item?->isApplicable)->toBeFalse();
})->with([
    'unanswered' => [['mon/m_a2' => null]],
    'service not offered' => [['services/anc' => 'no']],
    'service unanswered' => [['services/anc' => null]],
]);

it('counts a consistency answer k as k passes of 3 checks (D-67)', function (string $answer, array $expected): void {
    expect(koboValues(koboParse(koboRulesRow(['mon/m_c1' => $answer])))['m_c1'])->toBe($expected);
})->with([
    'none correct' => ['0', ['fail', 'fail', 'fail']],
    'two correct' => ['2', ['pass', 'pass', 'fail']],
    'all three' => ['3', ['pass', 'pass', 'pass']],
]);

it('makes an unanswered consistency item N/A, never the form\'s 1.5 of 1.5 (D-67)', function (): void {
    $row = koboRulesRow();
    unset($row['mon/m_c1']);

    expect(koboValues(koboParse($row))['m_c1'])->toBe(['na', 'na', 'na']);
});

it('keeps a consistency answer that is not 0 to 3 as invalid, for ITEMS_INCOMPLETE', function (string $answer): void {
    expect(koboValues(koboParse(koboRulesRow(['mon/m_c1' => $answer])))['m_c1'])->toBe(['invalid']);
})->with(['four' => ['4'], 'negative' => ['-1'], 'a fraction' => ['1.5'], 'a word' => ['two']]);

it('passes a validity item only when every number is entered and equal (D-68)', function (array $answers, string $expected): void {
    expect(koboValues(koboParse(koboRulesRow($answers)))['m_v1'])->toBe([$expected]);
})->with([
    'all equal' => [[], 'pass'],
    'equal as numbers' => [['mon/m_msf_1' => '40.0'], 'pass'],
    'one differs' => [['mon/m_reg_1' => '39'], 'fail'],
    'one missing' => [['mon/m_msf_1' => null], 'fail'],
    'none entered' => [['mon/m_dhis_1' => null, 'mon/m_msf_1' => null, 'mon/m_reg_1' => null], 'na'],
    'not a number' => [['mon/m_dhis_1' => 'forty'], 'invalid'],
]);

it('reads a quarterly visit: only its quarter\'s items, each in its month slot (D-70, D-71)', function (): void {
    $parsed = koboParse(koboRulesRow([
        'period_visit' => 'quarterly_dqa', 'review_month' => null, 'review_quarter' => 'q1',
        'qtr/q_a_jan' => 'yes', 'qtr/q_c_mar' => '3', 'qtr/q_dhis_1' => '12', 'qtr/q_reg_1' => '12', 'qtr/q_a_jul' => 'no',
    ]));

    expect($parsed->monthSlots)->toBeNull()
        ->and([$parsed->roundYear, $parsed->roundQuarter])->toBe([2026, 1])
        ->and(koboValues($parsed))->toBe(['q_a_jan' => ['pass'], 'q_c_mar' => ['pass', 'pass', 'pass'], 'q_v_jan' => ['pass']])
        ->and(collect($parsed->items)->mapWithKeys(fn (ItemResponseData $i): array => [$i->itemCode => $i->monthSlot])->all())
        ->toBe(['q_a_jan' => 1, 'q_c_mar' => 3, 'q_v_jan' => 1]);
});

it('reads no items and no round when the visit\'s mode or period cannot be read, never guessing', function (array $answers): void {
    $parsed = koboParse(koboRulesRow($answers));

    expect($parsed->items)->toBe([])->and($parsed->roundQuarter)->toBeNull();
})->with([
    'mode unknown' => [['period_visit' => 'weekly_dqa']],
    'mode missing' => [['period_visit' => null]],
    'month not a month' => [['review_month' => 'augst']],
    'quarter not q1–q4' => [['period_visit' => 'quarterly_dqa', 'review_month' => null, 'review_quarter' => 'q5']],
]);

it('marks a community visit or DDU at LGA level out of scope (D-74)', function (array $answers, string $reason): void {
    expect(koboParse(koboRulesRow($answers))->outOfScope)->toBe($reason);
})->with([
    'community visit' => [['field_visit_type' => 'community_visit'], 'community_visit'],
    'DDU at LGA level' => [['ddu_dqa' => 'ddu'], 'ddu'],
]);

it('scores nothing for a map whose named item rules are missing: no fallback to the name pattern', function (): void {
    config(['edqa_item_rules.kobo-test' => null]);

    expect(koboParse(koboRulesRow())->items)->toBe([]);
});

// ---- The real form's map and generated rules (config/edqa_item_rules.php)

it('maps every one of the real form\'s seven deployments to its map, with its 1,192 item rules', function (): void {
    $versions = ['vJFgGxS4Taw5EZuBt39iBJ', 'vcfuAHiKcZNtqZDtUi3Mdu', 'vgegCDwmG8zxcqTESJ5emv', 'vN8qMQcp7WqWED5xjRHvUg',
        'vEekbX34vXcFRPz6JqrdUE', 'vuqLpSWS4Kd5T2XrCKxV8A', 'vBnJSJRKjVuT4yDaRhKXye'];

    foreach ($versions as $version) {
        expect(FieldMap::for('kobo', $version)?->name)->toBe('kobo-2024010101');
    }
    $rules = FieldMap::for('kobo', 'vBnJSJRKjVuT4yDaRhKXye')?->itemRules() ?? [];
    expect($rules)->toHaveCount(1192)
        ->and(collect($rules)->countBy(fn (array $r): string => "{$r['dimension']}/{$r['mode']}")->sortKeys()->all())->toBe([
            'availability/monthly' => 45, 'availability/quarterly' => 496,
            'consistency/monthly' => 14, 'consistency/quarterly' => 168,
            'validity/monthly' => 37, 'validity/quarterly' => 432,
        ])
        ->and(collect($rules)->where('mode', 'quarterly')->every(fn (array $r): bool => in_array($r['slot'], [1, 2, 3], true) && in_array($r['quarter'], [1, 2, 3, 4], true)))->toBeTrue();
});

it('reads a real-form monthly visit: identity, round, and items from its monthly section only', function (): void {
    $parsed = koboParse([
        '_id' => 9001, '__version__' => 'vBnJSJRKjVuT4yDaRhKXye', 'meta/instanceID' => 'uuid:real-form-1', '_submission_time' => '2026-10-10T10:00:00',
        'start' => '2026-10-10T09:00:00.000+01:00', 'end' => '2026-10-10T11:00:00.000+01:00',
        'field_visit_type' => 'facility_visit', 'ddu_dqa' => 'dqa', 'period_visit' => 'monthly_dqa', 'review_month' => 'september',
        'description_facility/lga' => 'kaduna_north', 'description_facility/ward' => 'ward_x', 'description_facility/healthfacility' => 'kaduna_north_phc_1',
        'description_facility/review_year' => '2026', 'details_ass/name_ass' => 'Assessor A',
        'monthly_section/m_summ_form/m__1a' => 'yes', 'monthly_section/m_summ_form/m__1b' => 'no',
        'quarterly_section/q_summ_form/q__1a' => 'yes', // a quarterly answer: never read on a monthly visit
    ]);

    expect([$parsed->roundYear, $parsed->roundQuarter, $parsed->monthSlots])->toBe([2026, 3, [3]])
        ->and($parsed->facilityRef)->toBe('kaduna_north_phc_1')
        ->and($parsed->assessorName)->toBe('Assessor A')
        ->and(koboValues($parsed)['m__1a'] ?? null)->toBe(['pass'])
        ->and(koboValues($parsed)['m__1b'] ?? null)->toBe(['fail'])
        ->and(collect($parsed->items)->contains(fn (ItemResponseData $i): bool => str_starts_with($i->itemCode, 'q_')))->toBeFalse();
});

// ---- Out of scope through the pipeline (D-74)

it('stores a community visit as out_of_scope: no assessment, no quarantine, counted apart', function (): void {
    $parsed = koboParse(koboRulesRow(['field_visit_type' => 'community_visit']));
    $submission = submissionRow($parsed);

    $outcome = app(ProcessParsedBatch::class)->execute([[$submission, $parsed]])[0];

    expect($outcome->status)->toBe(SubmissionStatus::OutOfScope)
        ->and($submission->refresh()->status)->toBe(SubmissionStatus::OutOfScope)
        ->and(Assessment::query()->count())->toBe(0)
        ->and(QuarantinedRecord::query()->count())->toBe(0);
});
