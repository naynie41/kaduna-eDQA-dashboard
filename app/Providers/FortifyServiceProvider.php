<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Support\Auth\AccountLockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * Authentication (SECURITY.md §2): email + password, no MFA (D-35), with a progressive lockout
 * per account, password reset and password confirmation. No registration: accounts are created
 * by `edqa:admin:create` only.
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureAuthentication();
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Only active administrators who are not locked out can sign in. Every refusal (unknown
     * email, disabled, locked, wrong password) gets the same message, so the form never reveals
     * which accounts exist. Only a wrong password on an unlocked account counts towards a lockout.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', (string) $request->input(Fortify::username()))->first();
            if ($user === null || ! $user->is_active) {
                return null;
            }

            $lockout = app(AccountLockout::class);
            if ($lockout->isLocked($user)) {
                return null;
            }

            if (! Hash::check((string) $request->input('password'), $user->password)) {
                $lockout->recordFailure($user);

                return null;
            }

            $lockout->clear($user);

            return $user;
        });
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * 5 attempts a minute per email and IP, on top of the per-account lockout.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
