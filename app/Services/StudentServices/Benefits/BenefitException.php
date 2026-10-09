<?php

namespace App\Services\StudentServices\Benefits;

use RuntimeException;

/**
 * Rechazo de negocio de la API de beneficios de servicio. Lleva un
 * código estable (para que el Equipo 6 decida qué hacer) y el estado
 * HTTP con el que se responde.
 */
class BenefitException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
