<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Audited;
use App\Support\Auth\PasswordHistory;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An administrator: the only role (SECURITY.md §3). Signs in with a password alone (D-35).
 * Created, disabled, unlocked and deleted by artisan commands only. Audited without secrets:
 * never the password or the lockout counters.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property int $failed_login_count
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $locked_out_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
final class User extends Authenticatable implements MustVerifyEmail
{
    use Audited;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Mirrors the column defaults, so a new model matches its stored row. */
    protected $attributes = ['is_active' => true, 'failed_login_count' => 0];

    /**
     * Every password the account is given goes into its history (SECURITY.md §2: no reuse of the
     * last 5), whether set by edqa:admin:create, a reset or a change.
     */
    protected static function booted(): void
    {
        self::created(fn (User $user) => PasswordHistory::record($user));
        self::updated(function (User $user): void {
            if ($user->wasChanged('password')) {
                PasswordHistory::record($user);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'failed_login_count' => 'integer',
            'locked_until' => 'immutable_datetime',
            'locked_out_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected function auditedAttributes(): array
    {
        return ['name', 'email', 'is_active'];
    }
}
