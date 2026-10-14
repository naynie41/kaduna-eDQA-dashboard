<?php

declare(strict_types=1);

use App\Domain\Scoring\Enums\RescoreStatus;
use App\Domain\Scoring\Enums\RescoreTrigger;
use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

/** @param  array<string, mixed>  $overrides */
function rescoreRun(array $overrides = []): int
{
    return DB::table('rescore_runs')->insertGetId([
        'round_id' => $overrides['round_id'] ?? Rows::round(),
        'rule_version_id' => $overrides['rule_version_id'] ?? Rows::ruleVersion(),
        'trigger' => 'console',
        'status' => 'running',
        'total_assessments' => 10,
        'started_at' => now(),
        ...$overrides,
    ]);
}

it('accepts every RescoreTrigger and RescoreStatus the app defines', function (): void {
    $admin = Rows::user();
    foreach (RescoreTrigger::cases() as $trigger) {
        expect(rescoreRun(['trigger' => $trigger->value, 'requested_by' => $admin, 'reason' => 'A reason given']))->toBeInt();
    }
    foreach (RescoreStatus::cases() as $status) {
        expect(rescoreRun(['status' => $status->value]))->toBeInt();
    }
});

it('rejects an unknown trigger or status', function (): void {
    expectCheckViolation(fn () => rescoreRun(['trigger' => 'cron']), 'rescore_runs_trigger_valid');
    expectCheckViolation(fn () => rescoreRun(['status' => 'paused']), 'rescore_runs_status_valid');
});

it('rejects negative counts and more processed than total', function (string $column, int $value): void {
    expectCheckViolation(fn () => rescoreRun([$column => $value]), 'rescore_runs_counts_valid');
})->with([
    ['processed_assessments', 11],
    ['total_assessments', -1],
    ['changed_scores', -1],
    ['changed_flags', -1],
    ['unscorable', -1],
    ['failed_chunks', -1],
]);

it('rejects a manual rescore without a requester and a reason', function (): void {
    expectCheckViolation(fn () => rescoreRun(['trigger' => 'manual']), 'rescore_runs_manual_has_reason');
    expectCheckViolation(fn () => rescoreRun(['trigger' => 'manual', 'reason' => 'A reason given']), 'rescore_runs_manual_has_reason');
});

it('keeps one run per batch', function (): void {
    rescoreRun(['batch_id' => 'batch-1']);

    expectUniqueViolation(fn () => rescoreRun(['batch_id' => 'batch-1']), 'rescore_runs_batch_id_unique');
});

it('starts with no completed chunks', function (): void {
    expect(DB::table('rescore_runs')->where('id', rescoreRun())->value('completed_chunks'))->toBe('{}');
});
