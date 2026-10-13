<?php

namespace App\Services\StudentServices\Payments;

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

    public function capture(string $holdReference, int $amountCents, string $reason, string $idempotencyKey): string
    {
        if ($existing = $this->existing($idempotencyKey)) {
            return $existing;
        }

        $hold = $this->openHold($holdReference);

        if ($amountCents <= 0 || $amountCents > $this->remainingCents($hold)) {
            throw new PaymentException('El cobro debe ser mayor que cero y no puede superar lo retenido.');
        }

        $reference = $this->record($idempotencyKey, [
            'operation' => 'capture',
            'student_id' => $hold->student_id,
            'concept' => $hold->concept,
            'source_reference' => $hold->source_reference,
            'hold_reference' => $holdReference,
            'amount_cents' => $amountCents,
            'reason' => $reason,
            'status' => 'completed',
        ]);

        if ($this->remainingCents($hold) === 0) {
            $hold->update(['status' => 'closed']);
        }

        return $reference;
    }

    public function release(string $holdReference, string $reason, string $idempotencyKey): string
    {
        if ($existing = $this->existing($idempotencyKey)) {
            return $existing;
        }

        $hold = $this->openHold($holdReference);

        $reference = $this->record($idempotencyKey, [
            'operation' => 'release',
            'student_id' => $hold->student_id,
            'concept' => $hold->concept,
            'source_reference' => $hold->source_reference,
            'hold_reference' => $holdReference,
            'amount_cents' => $this->remainingCents($hold),
            'reason' => $reason,
            'status' => 'completed',
        ]);

        $hold->update(['status' => 'closed']);

        return $reference;
    }

    private function openHold(string $holdReference): ServiceCharge
    {
        $hold = ServiceCharge::query()
            ->where('reference', $holdReference)
            ->where('operation', 'hold')
            ->first();

        if ($hold === null || $hold->status !== 'held') {
            throw new PaymentException('La retención no existe o ya se cerró.');
        }

        return $hold;
    }

    /**
     * Lee las capturas guardadas, así que cambia después de cada cobro.
     *
     * @phpstan-impure
     */
    private function remainingCents(ServiceCharge $hold): int
    {
        $captured = (int) ServiceCharge::query()
            ->where('hold_reference', $hold->reference)
            ->where('operation', 'capture')
            ->sum('amount_cents');

        return (int) $hold->amount_cents - $captured;
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
