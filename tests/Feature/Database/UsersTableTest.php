<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function userRow(string $email): array
{
    return [
        'name' => 'Admin',
        'email' => $email,
        'password' => 'hash',
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('has an is_active flag that defaults to active', function (): void {
    $id = DB::table('users')->insertGetId(userRow('active@example.test'));

    expect(DB::table('users')->where('id', $id)->value('is_active'))->toBeTrue();
});

it('has the Fortify two-factor columns', function (): void {
    expect(Schema::hasColumns('users', [
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
    ]))->toBeTrue();
});

it('has no role column: there is one role, Administrator', function (): void {
    expect(Schema::hasColumn('users', 'role'))->toBeFalse();
});

it('rejects a second account with the same email', function (): void {
    DB::table('users')->insert(userRow('dup@example.test'));

    expectUniqueViolation(
        fn () => DB::table('users')->insert(userRow('dup@example.test')),
        'users_email_unique',
    );
});
