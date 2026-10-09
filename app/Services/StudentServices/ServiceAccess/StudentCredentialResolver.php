<?php

namespace App\Services\StudentServices\ServiceAccess;

/**
 * Modulo 5.11 - Contrato para resolver una credencial (UID NFC, QR o
 * captura manual) a un estudiante.
 *
 * Según la sección 15 del diseño, quien resuelve la credencial es el
 * Equipo 1 (credential_uid -> estudiante, estado, permisos básicos).
 * Mientras no exista su IdentityService/CredentialService se usa
 * LocalStudentCredentialResolver; para integrarlo basta con cambiar el
 * binding en AppServiceProvider.
 */
interface StudentCredentialResolver
{
    /**
     * @return array{student_id: string, name: string, status: string}|null
     */
    public function resolve(string $credential, string $method): ?array;
}
