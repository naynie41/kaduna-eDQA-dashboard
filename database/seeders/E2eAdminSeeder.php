<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The administrator the Playwright suite signs in as (`make e2e`), with a known password and a
 * fixed TOTP secret that tests/e2e/support/login.ts turns into codes. Local and the test suite
 * only, and never called by DatabaseSeeder: these credentials are public in the repository.
 */
final class E2eAdminSeeder extends Seeder
{
    public const EMAIL = 'e2e-admin@edqa.test';

    public const PASSWORD = 'E2e-Local-Only-2026';

    /** 32 base32 characters (160 bits): long enough for otplib's guardrails. */
    public const TOTP_SECRET = 'KADUNAEDQAEZEADMINTOTPSECRETABCD';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2eAdminSeeder only runs in local or testing, never '.app()->environment().'.');
        }

        $user = User::query()->firstOrNew(['email' => self::EMAIL]);
        $user->forceFill([
            'name' => 'E2E Administrator',
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
            'is_active' => true,
            'two_factor_secret' => encrypt(self::TOTP_SECRET),
            'two_factor_recovery_codes' => encrypt(json_encode([], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }
}
