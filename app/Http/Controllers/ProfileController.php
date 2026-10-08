<?php

namespace App\Http\Controllers;

use App\Actions\Users\DeleteUserAccount;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            // Only confirmation state is exposed to Inertia, never TOTP secrets
            // or recovery codes.
            'twoFactorEnabled' => ! is_null($request->user()->two_factor_confirmed_at),
            'twoFactorRequired' => $request->user()->requiresTwoFactorAuthentication(),
            'twoFactorConfigurationPending' => ! is_null($request->user()->two_factor_secret)
                && is_null($request->user()->two_factor_confirmed_at),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request, DeleteUserAccount $deleteAccount): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        $deleteAccount->execute($user);

        Auth::logout();
        // SessionGuard rotates remember_token during logout. Clear that
        // post-logout value too; a closed account must retain no remember key.
        User::withTrashed()->whereKey($user->getKey())->update(['remember_token' => null]);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
