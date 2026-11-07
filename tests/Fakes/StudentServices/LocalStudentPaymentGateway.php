<?php

namespace Tests\Fakes\StudentServices;

use App\Services\StudentServices\Payments\StudentPaymentGateway;
use App\Services\StudentServices\Payments\PaymentException;

use App\Models\StudentServices\Payments\ServiceCharge;
use Illuminate\Support\Str;

/**
 * Implementación local mientras llega la API del Equipo 2: registra cada
 * movimiento en `service_charges` con sus reglas (no cobrar más de lo
 * retenido, no operar una retención cerrada), pero no mueve saldo.
 */
class LocalStudentPaymentGateway implements StudentPaymentGateway
{
    public function hold(string $studentId, int $amountCents, string $concept, string $sourceReference, string $idempotencyKey): string
    {
        if ($amountCents <= 0) {
            throw new PaymentException('El depósito debe ser mayor que cero.');
        }

        return $this->record($idempotencyKey, [
            'operation' => 'hold',
            'student_id' => $studentId,
            'concept' => $concept,
            'source_reference' => $sourceReference,
            'amount_cents' => $amountCents,
            'status' => 'held',
        ]);
    }

    public function settleDeposit(string $holdReference, int $amountCents, string $reason, string $idempotencyKey): array
    {
        $hold = ServiceCharge::where('reference', $holdReference)->where('operation', 'hold')->first();
        $prior = ServiceCharge::where('idempotency_key', $idempotencyKey)->first();
        if (! $hold || $amountCents < 0 || $amountCents > $hold->amount_cents) {
            throw new PaymentException('La retención no existe o el cargo supera lo retenido.');
        }
        if ($prior && ($prior->operation !== 'settle' || $prior->hold_reference !== $holdReference
            || $prior->amount_cents !== $amountCents || $prior->reason !== $reason)) {
            throw new PaymentException('La clave corresponde a otro cierre.');
        }
        if (! $prior && $hold->status !== 'held') {
            throw new PaymentException('La retención ya está cerrada.');
        }
        $reference = $prior?->reference ?? $this->record($idempotencyKey, [
            'operation' => 'settle',
            'student_id' => $hold->student_id,
            'concept' => $hold->concept,
            'source_reference' => $hold->source_reference,
            'hold_reference' => $holdReference,
            'amount_cents' => $amountCents,
            'reason' => $reason,
            'status' => 'completed',
        ]);
        $hold->update(['status' => 'closed']);

        return [
            'hold_id' => $holdReference,
            'transaction_id' => $reference,
            'charged_cents' => $amountCents,
            'released_cents' => $hold->amount_cents - $amountCents,
        ];
    }

    public function lookupFinePayment(string $fineId, string $paymentId): array
    {
        throw new PaymentException('Finanzas no confirmó el pago.');
    }

    private function existing(string $idempotencyKey): ?string
    {
        return ServiceCharge::query()
            ->where('idempotency_key', $idempotencyKey)
            ->value('reference');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(string $idempotencyKey, array $attributes): string
    {
        if ($existing = $this->existing($idempotencyKey)) {
            return $existing;
        }

        $reference = 'LOC-'.Str::upper(Str::random(10));

        ServiceCharge::create([
            ...$attributes,
            'reference' => $reference,
            'provider' => 'local',
            'idempotency_key' => $idempotencyKey,
        ]);

        return $reference;
    }
}
