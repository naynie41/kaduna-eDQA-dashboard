<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Models\User;
use App\Support\Auth\PasswordChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Password change (SECURITY.md §2): the policy and history apply, the user's other sessions end,
 * this one gets a new ID, and the change is audited.
 */
final class SecurityController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/security', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        PasswordChange::apply($user, $request->string('password')->toString(), 'settings', keepSession: $request->session()->getId());
        $request->session()->regenerate(true);

        return back()->with('status', __('auth.settings.password_saved'));
    }
}
