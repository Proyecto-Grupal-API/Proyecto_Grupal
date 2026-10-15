<?php
namespace App\Services\StudentServices\Payments;

class PendingStudentPaymentGateway implements StudentPaymentGateway
{
    public function hold(string $studentId, int $amountCents, string $concept, string $sourceReference, string $idempotencyKey): string
    {
        throw new PaymentException('El depósito está pendiente de la API de cartera del equipo 2. No se realizó ningún cobro.');
    }
    public function capture(string $holdReference, int $amountCents, string $reason, string $idempotencyKey): string
    {
        throw new PaymentException('El cobro está pendiente de la API de cartera del equipo 2.');
    }
    public function release(string $holdReference, string $reason, string $idempotencyKey): string
    {
        throw new PaymentException('La liberación está pendiente de la API de cartera del equipo 2.');
    }
}
