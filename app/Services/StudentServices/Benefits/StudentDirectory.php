<?php

namespace App\Services\StudentServices\Benefits;

/**
 * Contrato para saber si un beneficiario existe y puede recibir
 * servicios. El dueño de esta información es el Equipo 1 (Identidad):
 * en la app integrada debe resolverse con su API
 * GET /api/v1/students/{studentId}/status (scope students:read),
 * no leyendo su colección users. Mientras tanto se usa
 * LocalStudentDirectory. El binding vive en AppServiceProvider.
 */
interface StudentDirectory
{
    /**
     * @return 'active'|'inactive'|'not_found'
     */
    public function status(string $studentId): string;
}
