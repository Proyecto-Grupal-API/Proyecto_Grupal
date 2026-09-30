<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\ConditionalPasswordUpdater;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, ConditionalPasswordUpdater $passwordUpdater): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request, $passwordUpdater) {
                $updated = $passwordUpdater->replace($user, $user->password, [
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                    'must_change_password' => false,
                    'account_activation_pending' => false,
                ]);
                if (! $updated) {
                    if ($request->expectsJson() && ! $request->headers->has('X-Inertia')) {
                        throw new ConflictHttpException('La credencial cambió durante la recuperación. Solicita un nuevo enlace si es necesario.');
                    }

                    throw ValidationException::withMessages([
                        'email' => 'La credencial cambió durante la recuperación. Verifica el estado antes de intentarlo de nuevo.',
                    ]);
                }

                event(new PasswordReset($user->fresh()));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status == Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
