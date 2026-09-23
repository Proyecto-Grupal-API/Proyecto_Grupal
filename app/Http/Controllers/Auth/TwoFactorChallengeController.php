<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request)
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        $user = User::find($request->session()->get('login.id'));

        if (! $user || is_null($user->two_factor_confirmed_at)) {
            $request->session()->forget(['login.id', 'login.remember']);

            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, TwoFactorAuthenticationProvider $provider)
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        $user = User::find($request->session()->get('login.id'));

        if (! $user || is_null($user->two_factor_confirmed_at)) {
            $request->session()->forget(['login.id', 'login.remember']);

            return redirect()->route('login');
        }

        // Validación por código de emergencia (recovery code)
        if ($request->filled('recovery_code')) {
            $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?? [];

            if (! in_array($request->recovery_code, $recoveryCodes)) {
                throw ValidationException::withMessages([
                    'recovery_code' => 'El código de recuperación proporcionado no es válido.',
                ]);
            }

            // Consumir el código usado
            $user->two_factor_recovery_codes = encrypt(json_encode(array_values(array_diff($recoveryCodes, [$request->recovery_code]))));
            $user->save();
        } 
        // Validación por código OTP
        elseif ($request->filled('code')) {
            $valid = $provider->verify(decrypt($user->two_factor_secret), $request->code);

            if (! $valid) {
                throw ValidationException::withMessages([
                    'code' => 'El código de verificación proporcionado no es válido.',
                ]);
            }
        } else {
            throw ValidationException::withMessages([
                'code' => 'Debes ingresar un código de verificación.',
            ]);
        }

        Auth::login($user, $request->session()->pull('login.remember', false));
        $request->session()->forget('login.id');
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
