<?php

namespace App\Services\StudentServices\Payments;

class PendingStudentPaymentGateway implements StudentPaymentGateway
{
    public function hold(string $studentId, int $amountCents, string $concept, string $sourceReference, string $idempotencyKey): string
    {
        throw new PaymentException('La retención está pendiente de la integración con Finanzas.');
    }

    public function settleDeposit(string $holdReference, int $amountCents, string $reason, string $idempotencyKey): array
    {
        throw new PaymentException('La liquidación del depósito está pendiente de la integración con Finanzas.');
    }

    public function lookupFinePayment(string $fineId, string $paymentId): array
    {
        throw new PaymentException('La verificación del pago está pendiente de la integración con Finanzas.');
    }
}
