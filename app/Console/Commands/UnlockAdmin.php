<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Auth\AccountLockout;
use Illuminate\Console\Command;

/**
 * Lifts a lockout (SECURITY.md §2): the only way out of the indefinite lock after 20 failed
 * sign-ins, and a way to end a 15-minute or 1-hour one early. Audited (auth: unlock).
 */
final class UnlockAdmin extends Command
{
    protected $signature = 'edqa:admin:unlock {email : The administrator\'s email address}';

    protected $description = 'Unlock an administrator account locked by failed sign-ins';

    public function handle(AccountLockout $lockout): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No administrator with email {$email}.");

            return self::FAILURE;
        }

        $lockout->unlock($user);
        $this->info("{$email} is unlocked; its failed sign-in count starts again from 0.");

        return self::SUCCESS;
    }
}
