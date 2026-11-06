<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The administrator the Playwright suite signs in as (`make e2e`, tests/e2e/auth.setup.ts), with a
 * known password (no MFA, D-35). Local and the test suite only, and never called by
 * DatabaseSeeder: these credentials are public in the repository.
 */
final class E2eAdminSeeder extends Seeder
{
    public const EMAIL = 'e2e-admin@edqa.test';

    public const PASSWORD = 'E2e-Local-Only-2026';

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
            // A rerun also lifts any lockout a failed e2e run left behind.
            'failed_login_count' => 0,
            'locked_until' => null,
            'locked_out_at' => null,
        ])->save();
    }
}
