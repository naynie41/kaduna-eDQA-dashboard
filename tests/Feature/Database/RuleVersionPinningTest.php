<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

// D-32: a closed round pins the rule version it was scored with; only one draft at a time.

it('rejects a closed round with no pinned rule version', function (): void {
    expectCheckViolation(fn () => Rows::round(['status' => 'closed', 'rule_version_id' => null]), 'rounds_closed_has_rule_version');
});

it('accepts a closed round with a pinned version and an open round with none', function (): void {
    expect(Rows::round(['status' => 'closed', 'rule_version_id' => Rows::ruleVersion()]))->toBeInt()
        ->and(Rows::round(['status' => 'open']))->toBeInt();
});

it('rejects a pinned version that does not exist', function (): void {
    expect(fn () => DB::transaction(fn () => Rows::round(['status' => 'closed', 'rule_version_id' => 999_999])))
        ->toThrow(QueryException::class, 'foreign key');
});

it('allows only one draft rule version at a time', function (): void {
    Rows::ruleVersion(['published_at' => null]);

    expectUniqueViolation(fn () => Rows::ruleVersion(['published_at' => null]), 'scoring_rule_versions_one_draft');
});

it('allows any number of published versions alongside the draft', function (): void {
    Rows::ruleVersion(['published_at' => null]);

    expect(Rows::ruleVersion())->toBeInt()->and(Rows::ruleVersion())->toBeInt();
});
