<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// ARCHITECTURE.md §4: all timestamps are timestamptz. Guards every future migration too.
it('stores every timestamp with a time zone', function (): void {
    $naive = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('data_type', 'timestamp without time zone')
        ->get(['table_name', 'column_name'])
        ->map(fn (object $c): string => "{$c->table_name}.{$c->column_name}")
        ->all();

    expect($naive)->toBe([]);
});

// ARCHITECTURE.md §4: all raw payloads are jsonb, never json.
it('stores every JSON column as jsonb', function (): void {
    $json = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('data_type', 'json')
        ->get(['table_name', 'column_name'])
        ->map(fn (object $c): string => "{$c->table_name}.{$c->column_name}")
        ->all();

    expect($json)->toBe([]);
});

// CONVENTION.md §8: each CHECK and UNIQUE rejects a bad raw insert, in a test. Every named
// constraint or unique index in our tables must appear in a tests/Feature/Database file.
// Laravel's own queue, cache and session tables are not ours to test.
it('has a constraint test for every CHECK and UNIQUE', function (): void {
    $framework = ['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'password_reset_tokens', 'sessions', 'migrations'];

    $names = collect(DB::select(<<<'SQL'
        select c.conrelid::regclass::text as tbl, c.conname as name
        from pg_constraint c join pg_namespace n on n.oid = c.connamespace
        where n.nspname = 'public' and c.contype in ('c', 'u')
        union
        select i.indrelid::regclass::text, ic.relname
        from pg_index i
        join pg_class ic on ic.oid = i.indexrelid
        join pg_namespace n on n.oid = ic.relnamespace
        where n.nspname = 'public' and i.indisunique and not i.indisprimary
        SQL))->reject(fn (object $c): bool => in_array($c->tbl, $framework, true))->pluck('name')->unique();

    $tests = collect(glob(__DIR__.'/*.php') ?: [])->map(fn (string $f): string => (string) file_get_contents($f))->implode("\n");
    $untested = $names->reject(fn (string $name): bool => str_contains($tests, "'{$name}'"))->values()->all();

    expect($names)->not->toBeEmpty()
        ->and($untested)->toBe([]);
});

// Postgres truncates identifiers to 63 characters without an error, so a long generated
// constraint name no longer matches what the migration (and its down()) refers to.
it('has no constraint or index name at the 63-character limit', function (): void {
    $names = collect(DB::select(<<<'SQL'
        select conname as name from pg_constraint c join pg_namespace n on n.oid = c.connamespace
        where n.nspname = 'public'
        union
        select indexname from pg_indexes where schemaname = 'public'
        SQL))->pluck('name')->filter(fn (string $name): bool => strlen($name) >= 63)->values()->all();

    expect($names)->toBe([]);
});
