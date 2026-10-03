<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Disables an administrator (SECURITY.md §12, suspected compromise): the account can no longer
 * sign in, and every open session and "remember me" token is ended now. Never deletes the
 * account, so the audit trail keeps its causer. Audited through the User model.
 */
final class DisableAdmin extends Command
{
    protected $signature = 'edqa:admin:disable {email : The administrator\'s email address}';

    protected $description = 'Disable an administrator account and end its sessions';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No administrator with email {$email}.");

            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->info("{$email} is already disabled.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['is_active' => false, 'remember_token' => null])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        $this->info("{$email} is disabled and signed out everywhere.");

        return self::SUCCESS;
    }
}
