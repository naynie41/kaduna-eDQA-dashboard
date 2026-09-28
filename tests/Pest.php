<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Feature tests boot the app and run against the edqa_test Postgres database (never SQLite).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit tests that need translations or config boot the app, without a database.
pest()->extend(TestCase::class)
    ->in('Unit');

// Permission checks as the runtime role (`make grants-check`): the schema is migrated
// beforehand by the migrator, so no RefreshDatabase here.
pest()->extend(TestCase::class)
    ->in('Grants');

// Migration round-trips run real DDL outside a test transaction, so no RefreshDatabase.
pest()->extend(TestCase::class)
    ->in('Migrations');

/**
 * Postgres data type of a column in the public schema, e.g. "jsonb", or null if absent.
 */
function columnType(string $table, string $column): ?string
{
    $type = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', $table)
        ->where('column_name', $column)
        ->value('data_type');

    return is_string($type) ? $type : null;
}

/**
 * CREATE INDEX statement for an index, or '' if it doesn't exist.
 */
function indexDefinition(string $index): string
{
    $definition = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('indexname', $index)
        ->value('indexdef');

    return is_string($definition) ? $definition : '';
}

/**
 * Assert that the query is rejected by the named CHECK constraint (SQLSTATE 23514).
 */
function expectCheckViolation(Closure $query, string $constraint): void
{
    expectSqlState($query, '23514', $constraint);
}

/**
 * Assert that the query is rejected by the named UNIQUE constraint or index (SQLSTATE 23505).
 */
function expectUniqueViolation(Closure $query, string $constraint): void
{
    expectSqlState($query, '23505', $constraint);
}

/**
 * Runs the query inside a savepoint, so a rejected statement doesn't abort the test's
 * transaction and later assertions in the same test still run.
 */
function expectSqlState(Closure $query, string $sqlState, string $constraint): void
{
    try {
        DB::transaction($query);
    } catch (QueryException $e) {
        expect((string) $e->getCode())->toBe($sqlState, "Expected SQLSTATE {$sqlState}, got {$e->getCode()}: {$e->getMessage()}")
            ->and($e->getMessage())->toContain("\"{$constraint}\"");

        return;
    }

    test()->fail("Expected SQLSTATE {$sqlState} from constraint \"{$constraint}\", but the query succeeded.");
}
