<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

/**
 * The mandatory 2FA setup page. Steps: start (Fortify enable) → scan the QR code and enter a
 * code (Fortify confirm) → save the recovery codes, shown here exactly once.
 */
final class TwoFactorSetupController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $recoveryCodes = $request->session()->get('recovery_codes');

        if ($user->two_factor_confirmed_at !== null && ! is_array($recoveryCodes)) {
            return redirect()->route('home');
        }

        $started = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        return Inertia::render('auth/two-factor-setup', [
            'step' => match (true) {
                is_array($recoveryCodes) => 'recovery-codes',
                $started => 'confirm',
                default => 'start',
            },
            'qrCodeSvg' => $started ? $user->twoFactorQrCodeSvg() : null,
            'setupKey' => $started ? Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret) : null,
            'recoveryCodes' => is_array($recoveryCodes) ? array_values($recoveryCodes) : null,
        ]);
    }
}
