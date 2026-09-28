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
