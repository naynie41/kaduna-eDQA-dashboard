<?php

declare(strict_types=1);

use App\Domain\Plan\Enums\PlanActionStatus;
use App\Domain\Scoring\Enums\Dimension;
use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

// ---- scoring_rule_versions

it('rejects two rule versions with the same number', function (): void {
    Rows::ruleVersion(['version' => 7]);

    expectUniqueViolation(fn () => Rows::ruleVersion(['version' => 7]), 'scoring_rule_versions_version_unique');
});

it('stores rule config as jsonb', function (): void {
    expect(columnType('scoring_rule_versions', 'config'))->toBe('jsonb');
});

// ---- plan_actions

it('accepts every PlanActionStatus the app defines', function (PlanActionStatus $status): void {
    expect(Rows::planAction(['status' => $status->value]))->toBeInt();
})->with(fn (): array => PlanActionStatus::cases());

it('rejects an unknown plan action status', function (): void {
    expectCheckViolation(fn () => Rows::planAction(['status' => 'blocked']), 'plan_actions_status_valid');
});

it('accepts every Dimension on plan actions', function (Dimension $dimension): void {
    expect(Rows::planAction(['dimension' => $dimension->value]))->toBeInt();
})->with(fn (): array => Dimension::cases());

it('rejects an unknown dimension on plan actions', function (): void {
    expectCheckViolation(fn () => Rows::planAction(['dimension' => 'timeliness']), 'plan_actions_dimension_valid');
});

it('stores an owner as one of the configured roles, never free text (D-79)', function (string $role): void {
    expect(Rows::planAction(['owner_role' => $role]))->toBeInt();
})->with(['lga_me_officer', 'state_me_team', 'programme_manager']);

it('lists the same roles in config as the CHECK holds', function (): void {
    expect(config('edqa.plan.roles'))->toBe(['lga_me_officer', 'state_me_team', 'programme_manager'])
        ->and(array_keys((array) __('plan.owners')))->toBe(config('edqa.plan.roles'));
});

it('rejects an owner role that is not a role', function (): void {
    expectCheckViolation(fn () => Rows::planAction(['owner_role' => 'Dr A. Bello']), 'plan_actions_owner_role_valid');
});

it('keeps a personal contact apart, optional and admin-only', function (): void {
    $id = Rows::planAction(['owner_contact' => 'Dr A. Bello, 0803 000 0000']);

    expect(DB::table('plan_actions')->where('id', $id)->value('owner_contact'))->toBe('Dr A. Bello, 0803 000 0000')
        ->and(DB::table('plan_actions')->where('id', Rows::planAction(['lga_id' => Rows::lga()]))->value('owner_contact'))->toBeNull();
});

it('rejects a second action for the same round, LGA and dimension', function (): void {
    $roundId = Rows::round();
    $lgaId = Rows::lga();
    Rows::planAction(['round_id' => $roundId, 'lga_id' => $lgaId]);

    expectUniqueViolation(
        fn () => Rows::planAction(['round_id' => $roundId, 'lga_id' => $lgaId]),
        'plan_actions_round_id_lga_id_dimension_unique',
    );
});

// ---- activity_log (spatie/laravel-activitylog, converted to jsonb and timestamptz)

it('stores activity properties as jsonb', function (): void {
    expect(columnType('activity_log', 'properties'))->toBe('jsonb');
});

it('records an activity entry with old and new values', function (): void {
    $id = DB::table('activity_log')->insertGetId([
        'log_name' => 'default',
        'description' => 'round.reopened',
        'event' => 'reopened',
        'properties' => json_encode(['old' => ['status' => 'closed'], 'attributes' => ['status' => 'open'], 'reason' => 'Late submissions from Zaria']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('activity_log')->where('id', $id)->value('properties'))->toContain('"reason"');
});
