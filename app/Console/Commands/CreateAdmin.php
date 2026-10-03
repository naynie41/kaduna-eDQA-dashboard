<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * The only way to create an administrator (SECURITY.md §2): no registration, no user-management
 * page. The account is verified and active; 2FA is set up at first sign-in. Audited through the
 * User model (source = console).
 */
final class CreateAdmin extends Command
{
    /** SECURITY.md §2: keep to three or fewer for v1; above that, revisit roles (Q-08). */
    private const RECOMMENDED_MAXIMUM = 3;

    protected $signature = 'edqa:admin:create';

    protected $description = 'Create an administrator account (interactive)';

    public function handle(): int
    {
        $input = [
            'name' => text('Name', required: true),
            'email' => text('Email', required: true),
            'password' => password('Password', required: true),
            'password_confirmation' => password('Confirm password', required: true),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'email_verified_at' => now(),
        ])->save();

        $this->info("Administrator {$user->email} created. They set up two-factor authentication at first sign-in.");

        $active = User::query()->where('is_active', true)->count();
        if ($active > self::RECOMMENDED_MAXIMUM) {
            $this->warn("There are now {$active} active administrators. SECURITY.md §2 recommends ".self::RECOMMENDED_MAXIMUM.' or fewer for v1; above that, revisit roles before launch (open question Q-08).');
        }

        return self::SUCCESS;
    }
}
