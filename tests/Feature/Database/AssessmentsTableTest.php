<?php

declare(strict_types=1);

use App\Domain\Scoring\Enums\Dimension;
use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

it('rejects a visit that ends before it starts', function (): void {
    expectCheckViolation(fn () => Rows::assessment([
        'started_at' => '2026-05-04 10:00:00+01',
        'ended_at' => '2026-05-04 09:59:00+01',
    ]), 'visit_dates_ordered');
});

it('accepts a visit that ends when it starts', function (): void {
    expect(Rows::assessment([
        'started_at' => '2026-05-04 10:00:00+01',
        'ended_at' => '2026-05-04 10:00:00+01',
    ]))->toBeInt();
});

it('rejects a second assessment of one facility in one round', function (): void {
    $roundId = Rows::round();
    $facilityId = Rows::facility();
    Rows::assessment(['round_id' => $roundId, 'facility_id' => $facilityId]);

    expectUniqueViolation(
        fn () => Rows::assessment(['round_id' => $roundId, 'facility_id' => $facilityId]),
        'assessments_round_id_facility_id_unique',
    );
});

it('defaults flags to an empty JSON array', function (): void {
    $id = Rows::assessment();

    expect(DB::table('assessments')->where('id', $id)->value('flags'))->toBe('[]')
        ->and(columnType('assessments', 'flags'))->toBe('jsonb')
        ->and(columnType('assessments', 'gps'))->toBe('jsonb');
});

it('rejects an item response month slot outside 1 to 3', function (int $slot): void {
    expectCheckViolation(fn () => Rows::itemResponse(['month_slot' => $slot]), 'month_slot_valid');
})->with([0, 4]);

it('accepts every Dimension on item responses', function (Dimension $dimension): void {
    expect(Rows::itemResponse(['dimension' => $dimension->value]))->toBeInt();
})->with(fn (): array => Dimension::cases());

it('rejects an unknown dimension on item responses', function (): void {
    expectCheckViolation(fn () => Rows::itemResponse(['dimension' => 'timeliness']), 'item_responses_dimension_valid');
});

it('indexes item responses by assessment, dimension and month slot', function (): void {
    expect(indexDefinition('item_responses_assessment_id_dimension_month_slot_index'))
        ->toContain('(assessment_id, dimension, month_slot)');
});
