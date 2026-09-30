<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\ConditionalPasswordUpdater;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private ConditionalPasswordUpdater $passwordUpdater) {}

    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        if (! $this->passwordUpdater->replace($user, $user->password, [
            'password' => Hash::make($input['password']),
        ])) {
            throw ValidationException::withMessages([
                'current_password' => 'La contraseña cambió durante la solicitud. Inténtalo de nuevo con la credencial vigente.',
            ])->errorBag('updatePassword');
        }

        $user->refresh();
    }
}
