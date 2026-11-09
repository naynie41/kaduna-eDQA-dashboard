<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
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

it('has the lockout columns and no two-factor columns (D-84)', function (): void {
    expect(Schema::hasColumns('users', ['failed_login_count', 'locked_until', 'locked_out_at']))->toBeTrue()
        ->and(Schema::hasColumn('users', 'two_factor_secret'))->toBeFalse();
});

it('rejects a negative failed sign-in count', function (): void {
    $id = DB::table('users')->insertGetId(userRow('count@example.test'));

    expect(fn () => DB::table('users')->where('id', $id)->update(['failed_login_count' => -1]))
        ->toThrow(QueryException::class, 'users_failed_login_count_non_negative');
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
