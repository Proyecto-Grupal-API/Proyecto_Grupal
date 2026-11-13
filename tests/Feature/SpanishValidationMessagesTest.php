<?php

use Illuminate\Support\Facades\Validator;

// Rúbrica 3.4: las validaciones del backend devuelven mensajes claros en el idioma de la interfaz.
test('validation messages and field names are returned in spanish by default', function () {
    expect(app()->getLocale())->toBe('es');
    $errors = Validator::make(['name' => str_repeat('x', 300), 'points_required' => 0],
        ['name' => 'required|string|max:255', 'points_required' => 'required|integer|min:1', 'email' => 'required|email'])->errors();
    expect($errors->first('name'))->toBe('El campo nombre no debe tener más de 255 caracteres.')
        ->and($errors->first('points_required'))->toBe('El campo puntos requeridos debe ser al menos 1.')
        ->and($errors->first('email'))->toBe('El campo correo electrónico es obligatorio.');
});

test('authentication failures are explained in spanish and unknown keys fall back safely', function () {
    expect(trans('auth.failed'))->toBe('Estas credenciales no coinciden con nuestros registros.')
        ->and(config('app.fallback_locale'))->toBe('en')
        ->and(trans('passwords.reset'))->toBe('Tu contraseña fue restablecida.');
});
