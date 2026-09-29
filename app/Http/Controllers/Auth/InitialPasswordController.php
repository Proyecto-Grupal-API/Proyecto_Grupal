<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\UserSession;
use App\Support\ExecutesMongoAtomically;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InitialPasswordController extends Controller
{
    use ExecutesMongoAtomically;

    public function edit(Request $request): Response|RedirectResponse
    {
        if ($request->user()->must_change_password !== true) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/InitialPassword');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->must_change_password === true, 409);

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();
        if (Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'La nueva contraseña debe ser diferente de la contraseña actual.',
            ]);
        }

        $currentSessionId = $request->session()->get('cd_session_id');

        $this->mongoTransaction(function () use ($user, $validated, $currentSessionId, $request): void {
            $user->forceFill([
                'password' => Hash::make($validated['password']),
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();

            $otherSessions = UserSession::where('user_id', (string) $user->getKey())
                ->whereNull('revoked_at');
            if ($currentSessionId) {
                $otherSessions->where('_id', '!=', $currentSessionId);
            }
            $otherSessions->update(['revoked_at' => now(), 'revoked_reason' => 'initial_password_changed']);

            SecurityEvent::log([
                'user_id' => (string) $user->getKey(),
                'session_id' => $currentSessionId,
                'type' => 'mandatory_password_changed',
                'severity' => 'info',
                'ip_address' => $request->ip(),
            ]);
        });

        $request->session()->regenerate();

        return redirect()->route($user->requiresTwoFactorAuthentication() && ! $user->two_factor_enabled
            ? 'two-factor.enrollment'
            : 'dashboard');
    }
}
