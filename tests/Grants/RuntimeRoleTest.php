<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Run by `make grants-check`: edqa_test is migrated by edqa_migrator and has had
// post-migrate-grants.sql applied (DEPLOY.md §8.2–8.3). These tests then act as edqa_app, the
// role the app containers use, through the pgsql_app_test connection.

function appRole(): Connection
{
    return DB::connection('pgsql_app_test');
}

/**
 * Assert the statement is refused for lack of privilege (SQLSTATE 42501).
 */
function expectPermissionDenied(Closure $statement): void
{
    try {
        $statement();
    } catch (QueryException $e) {
        expect((string) $e->getCode())->toBe('42501', $e->getMessage());

        return;
    }

    test()->fail('Expected permission denied (SQLSTATE 42501), but the statement succeeded.');
}

it('connects as the runtime role', function (): void {
    expect(appRole()->selectOne('select current_user as role')?->role)->toBe('edqa_app');
});

it('can refresh the aggregates through the function and read the view', function (): void {
    appRole()->select('select refresh_round_aggregates()');

    expect(appRole()->table('round_aggregates')->count())->toBeInt();
});

it('cannot refresh the view directly: only through the SECURITY DEFINER function', function (): void {
    expectPermissionDenied(fn () => appRole()->statement('REFRESH MATERIALIZED VIEW round_aggregates'));
});

it('can append to the audit trail but not change or remove it', function (): void {
    $id = appRole()->table('activity_log')->insertGetId([
        'log_name' => 'default',
        'description' => 'grants.probe',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expectPermissionDenied(fn () => appRole()->table('activity_log')->where('id', $id)->update(['description' => 'edited']));
    expectPermissionDenied(fn () => appRole()->table('activity_log')->where('id', $id)->delete());
    expectPermissionDenied(fn () => appRole()->statement('TRUNCATE activity_log'));
});

it('can read the 23 LGAs but not insert or change them', function (): void {
    expect(appRole()->table('lgas')->count())->toBeInt();

    expectPermissionDenied(fn () => appRole()->table('lgas')->insert(['name' => 'Twenty-fourth', 'code' => 'X24']));
    expectPermissionDenied(fn () => appRole()->table('lgas')->update(['name' => 'Renamed']));
});

it('cannot create a table', function (): void {
    expectPermissionDenied(fn () => appRole()->statement('create table grants_probe (id integer)'));
});

it('can write rows in tables the migrator created', function (): void {
    appRole()->beginTransaction();

    try {
        appRole()->table('users')->insert([
            'name' => 'Grants probe',
            'email' => 'grants-probe@example.test',
            'password' => 'not-a-real-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(appRole()->table('users')->where('email', 'grants-probe@example.test')->count())->toBe(1);
    } finally {
        appRole()->rollBack();
    }
});
