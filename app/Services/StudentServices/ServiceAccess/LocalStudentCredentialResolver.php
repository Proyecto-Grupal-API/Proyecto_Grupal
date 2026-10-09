<?php

namespace App\Services\StudentServices\ServiceAccess;

use App\Models\User;
use MongoDB\BSON\ObjectId;

/**
 * Resolución provisional de credenciales mientras el Equipo 1 entrega
 * su servicio de identidad. Acepta:
 *  - el id del usuario (24 caracteres hex), con o sin prefijo QR-/NFC-/EST-
 *  - el correo del usuario
 */
class LocalStudentCredentialResolver implements StudentCredentialResolver
{
    public function resolve(string $credential, string $method): ?array
    {
        $value = trim($credential);

        if ($value === '') {
            return null;
        }

        $withoutPrefix = preg_replace('/^(QR|NFC|EST)[-:]/i', '', $value) ?? $value;

        $user = null;

        if (preg_match('/^[a-f0-9]{24}$/i', $withoutPrefix) === 1) {
            $user = User::query()->where('_id', new ObjectId(strtolower($withoutPrefix)))->first();
        }

        if ($user === null && str_contains($value, '@')) {
            $user = User::query()->where('email', strtolower($value))->first();
        }

        if ($user === null) {
            return null;
        }

        return [
            'student_id' => (string) $user->getAuthIdentifier(),
            'name' => (string) $user->name,
            'status' => 'active',
        ];
    }
}
