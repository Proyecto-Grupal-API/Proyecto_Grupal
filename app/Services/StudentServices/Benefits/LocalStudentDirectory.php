<?php

namespace App\Services\StudentServices\Benefits;

use App\Models\User;
use MongoDB\BSON\ObjectId;

/**
 * Implementación provisional: el beneficiario es el User._id (el mismo
 * identificador que el Equipo 1 declara canónico) y se considera activo
 * si la cuenta existe.
 */
class LocalStudentDirectory implements StudentDirectory
{
    public function status(string $studentId): string
    {
        if (preg_match('/^[a-f0-9]{24}$/i', $studentId) !== 1) {
            return 'not_found';
        }

        $exists = User::query()->where('_id', new ObjectId(strtolower($studentId)))->exists();

        return $exists ? 'active' : 'not_found';
    }
}
