<?php

namespace App\Services\PointsLedger\Exception;

use RuntimeException;

class InsufficientPointsException extends RuntimeException
{
    public static function forStudent(string $studentId, int $available, int $required): self
    {
        return new self(
            "Saldo insuficiente para el estudiante [{$studentId}]: disponible {$available}, requerido {$required}."
        );
    }
}
