<?php

namespace App\Services\StudentServices\Payments;

interface StudentPaymentGateway
{
    /** Returns the retention reference. Replays must preserve the original operation. */
    public function hold(string $studentId, int $amountCents, string $concept, string $sourceReference, string $idempotencyKey): string;

    /**
     * Closes a deposit in one operation, charging the assessed amount and releasing the remainder.
     *
     * @return array{hold_id:string, transaction_id:string, charged_cents:int, released_cents:int}
     */
    public function settleDeposit(string $holdReference, int $amountCents, string $reason, string $idempotencyKey): array;

    /**
     * Reads financial evidence through the authorized Finance API.
     *
     * @return array{payment_id:string, student_id:string, amount_cents:int, currency:string, source_reference:string, concept:string, status:string}
     */
    public function lookupFinePayment(string $fineId, string $paymentId): array;
}
