<?php

namespace App\Services\StudentServices\Payments;

/**
 * Operaciones de dinero que el Equipo 5 necesita del Equipo 2 (wallet).
 * Hoy la implementación es local; cuando el Equipo 2 publique sus rutas de
 * retenciones (`/holds`, `/holds/{id}/capture`, `/holds/{id}/release`) basta
 * con otra implementación de esta interfaz.
 *
 * Todas las operaciones son idempotentes por `$idempotencyKey`: repetirlas
 * devuelve la misma referencia sin duplicar el movimiento.
 */
interface StudentPaymentGateway
{
    /**
     * Retiene un depósito del alumno. Devuelve la referencia de la retención.
     *
     * @throws PaymentException si no se pudo retener.
     */
    public function hold(string $studentId, int $amountCents, string $concept, string $sourceReference, string $idempotencyKey): string;

    /**
     * Cobra parte o todo lo retenido. Devuelve la referencia del cobro.
     *
     * @throws PaymentException
     */
    public function capture(string $holdReference, int $amountCents, string $reason, string $idempotencyKey): string;

    /**
     * Libera lo que quede retenido. Devuelve la referencia de la liberación.
     *
     * @throws PaymentException
     */
    public function release(string $holdReference, string $reason, string $idempotencyKey): string;
}
