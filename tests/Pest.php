<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
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
 * Enables and confirms TOTP 2FA for the user through Fortify's own routes, as the setup page
 * does. Returns the confirmation response and the TOTP secret.
 *
 * @return array{0: TestResponse, 1: string}
 */
function confirmTwoFactor(User $user): array
{
    test()->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertRedirect();

    $secret = decrypt((string) $user->refresh()->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $response = test()->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.confirm'), ['code' => $code]);

    return [$response, $secret];
}

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
