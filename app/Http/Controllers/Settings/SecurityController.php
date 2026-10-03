<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Password change and recovery codes. 2FA itself is mandatory and set up on first sign-in
 * (TwoFactorSetupController); there is no switch to turn it off here.
 */
final class SecurityController extends Controller
{
    public function edit(Request $request): Response
    {
        $codes = $request->session()->get('recovery_codes');

        return Inertia::render('settings/security', [
            'status' => $request->session()->get('status'),
            // Flashed once by GenerateNewRecoveryCodes, never re-displayed.
            'recoveryCodes' => is_array($codes) ? array_values($codes) : null,
        ]);
    }

    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()?->update(['password' => $request->password]);

        return back()->with('status', __('auth.settings.password_saved'));
    }
}
