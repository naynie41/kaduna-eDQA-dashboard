<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The last passwords of each administrator, as hashes only (SECURITY.md §2: no reuse of the last
 * 5). Every password the account is given is recorded (User model events); older ones are pruned.
 */
final class PasswordHistory
{
    public static function record(User $user): void
    {
        DB::table('password_histories')->insert([
            'user_id' => $user->id,
            'password_hash' => $user->password,
            'created_at' => now(),
        ]);

        $keep = DB::table('password_histories')->where('user_id', $user->id)
            ->orderByDesc('id')->limit(self::size())->pluck('id');
        DB::table('password_histories')->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();
    }

    /** Whether the plain password is the current one or one of the recent ones. */
    public static function wasUsed(User $user, string $password): bool
    {
        $hashes = DB::table('password_histories')->where('user_id', $user->id)
            ->orderByDesc('id')->limit(self::size())->pluck('password_hash')
            ->push($user->password);

        return $hashes->contains(fn (string $hash): bool => Hash::check($password, $hash));
    }

    public static function size(): int
    {
        return (int) config('edqa.auth.password_history');
    }
}
