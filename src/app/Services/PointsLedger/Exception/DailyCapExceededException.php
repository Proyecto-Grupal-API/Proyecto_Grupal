<?php

namespace App\Services\PointsLedger\Exception;

use RuntimeException;

/**
 * Se lanza solo cuando el tope diario ya está completamente agotado (remaining <= 0).
 * Si aún queda margen parcial, el servicio recorta los puntos otorgados en vez de lanzar esto.
 */
class DailyCapExceededException extends RuntimeException
{
    public static function forStudentAndRule(string $studentId, string $ruleId, int $dailyCap): self
    {
        return new self(
            "El estudiante [{$studentId}] ya alcanzó el tope diario ({$dailyCap} pts) para la regla [{$ruleId}]."
        );
    }
}
