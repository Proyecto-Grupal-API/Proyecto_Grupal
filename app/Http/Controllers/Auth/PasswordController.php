<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\ConditionalPasswordUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request, ConditionalPasswordUpdater $passwordUpdater): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();
        $updated = $passwordUpdater->replace($user, $user->password, [
            'password' => Hash::make($validated['password']),
        ]);
        if (! $updated) {
            if ($request->expectsJson() && ! $request->headers->has('X-Inertia')) {
                throw new ConflictHttpException('La contraseña cambió durante la solicitud.');
            }

            throw ValidationException::withMessages([
                'current_password' => 'La contraseña cambió durante la solicitud. Inténtalo de nuevo con la credencial vigente.',
            ]);
        }

        $user->refresh();

        return back();
    }
}
