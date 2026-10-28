<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

// submission_sources and the source-aware counters (ARCHITECTURE.md §4, §5.0, Phase 2C step 1).

function sourceRow(array $overrides = []): array
{
    return ['key' => 'aggregate', 'type' => 'odk_central', 'name' => 'Aggregate', 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now(), ...$overrides];
}

it('allows each source key once (submission_sources_key_unique)', function (): void {
    expectUniqueViolation(fn () => DB::table('submission_sources')->insert(sourceRow(['key' => 'odk'])), 'submission_sources_key_unique');
});

it('accepts only the two source types (submission_sources_type_valid)', function (string $type): void {
    expectCheckViolation(fn () => DB::table('submission_sources')->insert(sourceRow(['type' => $type])), 'submission_sources_type_valid');
})->with(['odk_aggregate', 'surveycto', 'csv', '']);

it('keeps keys short, lower-case and safe for paths (submission_sources_key_format)', function (string $key): void {
    expectCheckViolation(fn () => DB::table('submission_sources')->insert(sourceRow(['key' => $key])), 'submission_sources_key_format');
})->with(['Kobo', '1kobo', 'kobo/../x', 'kobo-eu', '']); // longer than 32 is refused by the column itself

it('accepts a well-formed source', function (): void {
    expect(DB::table('submission_sources')->insertGetId(sourceRow(['key' => 'kobo_eu', 'type' => 'kobo'])))->toBeInt();
});

it('never counts a negative cross-source duplicate (pull_runs_cross_source_duplicates_nonnegative)', function (): void {
    expectCheckViolation(fn () => Rows::pullRun(['cross_source_duplicates' => -1]), 'pull_runs_cross_source_duplicates_nonnegative');
});

it('refuses a submission, pull run or quarantined record with no source', function (string $table, Closure $row): void {
    expectSqlState(fn () => DB::table($table)->insert([...$row(), 'source_id' => null]), '23502', 'source_id');
})->with([
    'submissions' => ['submissions', fn (): array => ['external_id' => 'x', 'instance_id' => 'uuid:no-source', 'form_ref' => 'dqa', 'form_version' => '2026.1', 'payload' => '{}', 'submitted_at' => now(), 'received_at' => now(), 'status' => 'received']],
    'pull_runs' => ['pull_runs', fn (): array => ['trigger_type' => 'scheduled', 'started_at' => now()]],
    'quarantined_records' => ['quarantined_records', fn (): array => ['submission_id' => Rows::submission(), 'instance_id' => 'uuid:q', 'payload' => '{}', 'failures' => '[]', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]],
]);
