<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Run by `make grants-check` as edqa_app, the role the app containers use, against an
// edqa_test already migrated by edqa_migrator (DEPLOY.md §8.2). Not part of `make test`,
// which connects as the migrator; phpunit.xml does not list this directory.

it('connects as the runtime role', function (): void {
    expect(DB::selectOne('select current_user as role')?->role)->toBe('edqa_app');
});

it('cannot change the schema', function (): void {
    expect(fn () => DB::statement('create table grants_probe (id integer)'))
        ->toThrow(QueryException::class, 'permission denied');
});

it('can write rows in tables the migrator created', function (): void {
    DB::beginTransaction();

    try {
        DB::table('users')->insert([
            'name' => 'Grants probe',
            'email' => 'grants-probe@example.test',
            'password' => 'not-a-real-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::table('users')->where('email', 'grants-probe@example.test')->count())->toBe(1);
    } finally {
        DB::rollBack();
    }
});
