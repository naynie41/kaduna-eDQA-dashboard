<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Migration tests run real DDL outside a test transaction, on the shared test database (no
// database trait, so parallel testing gives them no database of their own). They live in this one
// file so they run one after another in one worker, never two migrations at once.

// CLAUDE.md hard rule 4: every migration is reversible. Rolls back one migration at a time so
// a broken down() is pinned to the migration that owns it, then proves nothing was left behind
// and that the schema migrates up again.
it('rolls back every migration one step at a time, then migrates again', function (): void {
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    $total = DB::table('migrations')->count();

    expect($total)->toBeGreaterThan(0);

    for ($remaining = $total; $remaining > 0; $remaining--) {
        $migration = DB::table('migrations')->orderByDesc('id')->value('migration');
        $this->artisan('migrate:rollback', ['--step' => 1, '--no-interaction' => true])->assertSuccessful();

        expect(DB::table('migrations')->count())->toBe($remaining - 1, "rolling back {$migration}");
    }

    $leftover = collect(DB::select(<<<'SQL'
        select 'table ' || tablename as object from pg_tables
        where schemaname = 'public' and tablename <> 'migrations'
        union all
        select 'materialized view ' || matviewname from pg_matviews where schemaname = 'public'
        union all
        -- our functions only: extension functions (pg_trgm) are excluded
        select 'function ' || p.proname from pg_proc p
        join pg_namespace n on n.oid = p.pronamespace
        where n.nspname = 'public'
          and not exists (select 1 from pg_depend d where d.objid = p.oid and d.deptype = 'e')
        SQL))->pluck('object')->all();
    expect($leftover)->toBe([]);

    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    expect(DB::table('migrations')->count())->toBe($total);
});

// Phase 2C step 1 (ARCHITECTURE.md §4, §5.0): the ODK-only ingestion tables become source-aware.
// Data already stored is kept: every existing row belongs to the 'odk' source, external_id
// comes from the stored payload's __id, and the migration goes down and up again with the data.

const SOURCE_MIGRATION = '2026_10_28_000100_make_ingestion_source_aware';

/** Migrates everything, then rolls back to just before the source-aware migration. */
function beforeSourceAware(mixed $test): void
{
    $test->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    expect(DB::table('migrations')->orderByDesc('id')->value('migration'))->toBe(SOURCE_MIGRATION);
    $test->artisan('migrate:rollback', ['--step' => 1, '--no-interaction' => true])->assertSuccessful();
}

/** Rows as Phases 2A–2B stored them, in the ODK-only tables. @return array<string, int> */
function legacyRows(): array
{
    $now = now();
    $submission = DB::table('submissions')->insertGetId([
        'instance_id' => 'uuid:11111111-1111-4111-8111-111111111111', 'deprecated_id' => null,
        'form_id' => 'dqa', 'form_version' => '2026.1',
        'payload' => json_encode(['__id' => 'uuid:11111111-1111-4111-8111-111111111111', '__system' => ['formVersion' => '2026.1'], 'meta' => ['instanceID' => 'uuid:11111111-1111-4111-8111-111111111111']]),
        'submitted_at' => $now, 'received_at' => $now, 'status' => 'quarantined',
    ]);
    // An edited submission: Central's __id is the first version's instance ID, not this one's.
    $edited = DB::table('submissions')->insertGetId([
        'instance_id' => 'uuid:22222222-2222-4222-8222-222222222222', 'deprecated_id' => 'uuid:33333333-3333-4333-8333-333333333333',
        'form_id' => 'dqa', 'form_version' => '2026.1',
        'payload' => json_encode(['__id' => 'uuid:33333333-3333-4333-8333-333333333333', 'meta' => ['instanceID' => 'uuid:22222222-2222-4222-8222-222222222222']]),
        'submitted_at' => $now, 'received_at' => $now, 'status' => 'received',
    ]);
    $record = DB::table('quarantined_records')->insertGetId([
        'submission_id' => $submission, 'instance_id' => 'uuid:11111111-1111-4111-8111-111111111111',
        'payload' => json_encode(['__id' => 'x']), 'failures' => json_encode([['code' => 'LGA_UNKNOWN', 'severity' => 'hard', 'detail' => 'lga was blank']]),
        'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $sync = DB::table('odk_form_syncs')->insertGetId([
        'project_id' => 7, 'form_id' => 'dqa', 'last_pulled_at' => '2026-10-01 10:00:00+01', 'last_edit_at' => '2026-10-02 11:00:00+01',
        'last_instance_id' => 'uuid:11111111-1111-4111-8111-111111111111', 'backfill_skip' => 1500, 'last_error' => null,
        'submission_count' => 2, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $run = DB::table('odk_pull_runs')->insertGetId([
        'trigger_type' => 'scheduled', 'started_at' => $now, 'finished_at' => $now, 'fetched' => 2, 'parsed' => 2,
        'accepted' => 0, 'flagged' => 1, 'quarantined' => 1, 'duplicates' => 0, 'outcome' => 'succeeded',
    ]);
    $attachment = DB::table('odk_attachments')->insertGetId([
        'submission_id' => $submission, 'filename' => 'register.jpg', 'mime' => 'image/jpeg', 'size' => 1200,
        'path' => 'uuid:11111111-1111-4111-8111-111111111111/register.jpg', 'status' => 'fetched', 'fetched_at' => $now,
    ]);

    return compact('submission', 'edited', 'record', 'sync', 'run', 'attachment');
}

function columnNullable(string $table, string $column): bool
{
    return DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', $table)->where('column_name', $column)->value('is_nullable') === 'YES';
}

it('keeps every stored row and gives it to the odk source', function (): void {
    beforeSourceAware($this);
    $ids = legacyRows();

    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();

    $odk = DB::table('submission_sources')->where('key', 'odk')->sole();
    expect($odk->type)->toBe('odk_central')->and((bool) $odk->is_enabled)->toBeTrue();

    foreach (['submissions', 'quarantined_records', 'source_syncs', 'pull_runs'] as $table) {
        expect(DB::table($table)->whereNull('source_id')->count())->toBe(0, $table)
            ->and(DB::table($table)->where('source_id', $odk->id)->count())->toBeGreaterThan(0, $table)
            ->and(columnNullable($table, 'source_id'))->toBeFalse($table);
    }

    // The renamed tables and columns, with the data in them.
    expect(Schema::hasTable('odk_form_syncs'))->toBeFalse()
        ->and(Schema::hasTable('odk_pull_runs'))->toBeFalse()
        ->and(Schema::hasTable('odk_attachments'))->toBeFalse();
    $sync = DB::table('source_syncs')->where('id', $ids['sync'])->sole();
    expect($sync->cursor_instance_id)->toBe('uuid:11111111-1111-4111-8111-111111111111')
        ->and((int) $sync->backfill_checkpoint)->toBe(1500)
        ->and((string) $sync->cursor_at)->toStartWith('2026-10-01')
        ->and((string) $sync->edit_cursor_at)->toStartWith('2026-10-02')
        ->and($sync->form_ref)->toBe('7/dqa')
        ->and($sync->last_success_at)->toBeNull()
        ->and($sync->last_reconciled_at)->toBeNull();
    $run = DB::table('pull_runs')->where('id', $ids['run'])->sole();
    expect((int) $run->flagged)->toBe(1)->and((int) $run->parsed)->toBe(2)->and((int) $run->cross_source_duplicates)->toBe(0);
    $attachment = DB::table('submission_attachments')->where('id', $ids['attachment'])->sole();
    expect($attachment->filename)->toBe('register.jpg')->and($attachment->source_url)->toBeNull();

    // external_id from the stored payload's __id; the payload itself untouched.
    $submission = DB::table('submissions')->where('id', $ids['submission'])->sole();
    expect($submission->external_id)->toBe('uuid:11111111-1111-4111-8111-111111111111')
        ->and($submission->form_ref)->toBe('dqa')
        ->and($submission->source_updated_at)->toBeNull()
        ->and($submission->deleted_at_source)->toBeNull()
        ->and(json_decode((string) $submission->payload, true)['__system'])->toBe(['formVersion' => '2026.1']);
    expect(DB::table('submissions')->where('id', $ids['edited'])->value('external_id'))->toBe('uuid:33333333-3333-4333-8333-333333333333');
    expect(DB::table('quarantined_records')->where('id', $ids['record'])->value('source_id'))->toBe($odk->id);
});

it('names its constraints and indexes for the new tables, and refuses an unknown source type', function (): void {
    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();

    $constraints = collect(DB::select("select conname from pg_constraint where conrelid::regclass::text in ('source_syncs', 'pull_runs', 'submission_attachments', 'submission_sources')"))->pluck('conname');
    expect($constraints->filter(fn (string $name): bool => str_starts_with($name, 'odk_'))->values()->all())->toBe([])
        ->and($constraints)->toContain('pull_runs_trigger_type_valid', 'pull_runs_outcome_valid', 'pull_runs_parsed_nonnegative', 'submission_attachments_status_valid', 'submission_sources_type_valid', 'source_syncs_source_id_unique');
    expect(collect(DB::select("select indexname from pg_indexes where tablename = 'submissions'"))->pluck('indexname'))
        ->toContain('submissions_source_id_external_id_index');

    expectCheckViolation(fn () => DB::table('submission_sources')->insert(['key' => 'aggregate', 'type' => 'odk_aggregate', 'name' => 'ODK Aggregate', 'is_enabled' => true]), 'submission_sources_type_valid');
});

it('goes down with the data back in the ODK tables, and up again', function (): void {
    beforeSourceAware($this);
    $ids = legacyRows();
    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();

    $this->artisan('migrate:rollback', ['--step' => 1, '--no-interaction' => true])->assertSuccessful();

    expect(Schema::hasTable('submission_sources'))->toBeFalse()
        ->and(Schema::hasColumn('submissions', 'source_id'))->toBeFalse()
        ->and(Schema::hasColumn('submissions', 'form_id'))->toBeTrue();
    $sync = DB::table('odk_form_syncs')->where('id', $ids['sync'])->sole();
    expect((int) $sync->project_id)->toBe(7)->and($sync->form_id)->toBe('dqa')->and((int) $sync->backfill_skip)->toBe(1500)
        ->and($sync->last_instance_id)->toBe('uuid:11111111-1111-4111-8111-111111111111')
        ->and((string) $sync->last_edit_at)->toStartWith('2026-10-02');
    expect((int) DB::table('odk_pull_runs')->where('id', $ids['run'])->value('flagged'))->toBe(1)
        ->and(DB::table('odk_attachments')->where('id', $ids['attachment'])->value('filename'))->toBe('register.jpg')
        ->and(DB::table('submissions')->where('id', $ids['submission'])->value('form_id'))->toBe('dqa');

    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    expect(DB::table('source_syncs')->where('id', $ids['sync'])->value('backfill_checkpoint'))->toBe(1500)
        ->and(DB::table('submissions')->where('id', $ids['edited'])->value('external_id'))->toBe('uuid:33333333-3333-4333-8333-333333333333')
        ->and(DB::table('submission_sources')->count())->toBe(1);
});

it('refuses to merge two ODK cursor rows rather than guess which one is current', function (): void {
    beforeSourceAware($this);
    legacyRows();
    DB::table('odk_form_syncs')->insert(['project_id' => 8, 'form_id' => 'dqa-old', 'backfill_skip' => 0, 'submission_count' => 0, 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => $this->artisan('migrate', ['--no-interaction' => true]))->toThrow(RuntimeException::class, 'odk_form_syncs');
    expect(Schema::hasTable('odk_form_syncs'))->toBeTrue()
        ->and(Schema::hasTable('submission_sources'))->toBeFalse();
});
