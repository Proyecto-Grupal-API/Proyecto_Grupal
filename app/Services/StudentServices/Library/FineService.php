<?php

namespace App\Services\StudentServices\Library;

use App\Models\StudentServices\Library\LibraryFine;
use App\Models\StudentServices\Library\Loan;
use App\Services\StudentServices\Audit\ServiceAuditor;
use App\Services\StudentServices\Payments\LibraryFineFinanceClient;
use App\Services\StudentServices\Payments\PaymentException;
use App\Services\StudentServices\Payments\StudentPaymentGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use MongoDB\Driver\Exception\BulkWriteException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use RuntimeException;

class FineService
{
    private const TYPES = [
        'late',
        'damage',
        'loss',
        'other',
    ];

    public function __construct(
        private readonly StudentPaymentGateway $payments,
        private readonly LibraryFineFinanceClient $finance,
    ) {}

    public function financeEnabled(): bool
    {
        return $this->finance->enabled();
    }

    public function startFinancePayment(LibraryFine $fine): ?string
    {
        if (! $this->financeEnabled()) {
            throw new PaymentException('El pago de multas con Finanzas no está habilitado.');
        }
        if ($fine->status === 'paid' && $fine->verified_payment_id) {
            return null;
        }

        // Persist the marker before contacting Finance, even if the HTTP response is lost.
        LibraryFine::whereKey($fine->getKey())->where('status', 'pending')->where('finance_requested', '!=', true)
            ->update(['finance_requested' => true]);
        $fine->refresh();
        if ($fine->status !== 'pending' || ! $fine->finance_requested) {
            throw new PaymentException('Solo una multa pendiente se puede pagar.');
        }

        $payment = $this->finance->prepare($fine);
        if ($payment['status'] === 'CONFIRMED') {
            $this->recordPayment($fine, $payment['payment_id'], $payment);

            return null;
        }

        $path = $payment['confirmation_path'] ?? null;
        if ($payment['status'] !== 'PENDING' || ! is_string($path)
            || ! preg_match('~\A/finanzas/multas-biblioteca/solicitudes/[0-9a-f-]{36}\z~', $path)
            || ! Str::isUuid(basename($path))) {
            throw new PaymentException('Finanzas no devolvió una solicitud de pago válida.');
        }

        return $path;
    }

    public function reconcileFinancePayment(LibraryFine $fine): bool
    {
        $fine->refresh();
        if ($fine->status === 'paid' && $fine->verified_payment_id) {
            return true;
        }
        if ($fine->status !== 'pending' || ! $fine->finance_requested) {
            return false;
        }

        $payment = $this->finance->lookup($fine);
        if (($payment['status'] ?? null) !== 'CONFIRMED') {
            return false;
        }

        $this->recordPayment($fine, $payment['payment_id'], $payment);

        return true;
    }

    public function createFine(
        Loan $loan,
        string $type,
        int $amountCents,
        string $reason,
        ?string $notes = null
    ): LibraryFine {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(
                'El tipo de multa no es válido.'
            );
        }

        if ($amountCents <= 0) {
            throw new InvalidArgumentException(
                'El monto de la multa debe ser mayor a cero.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Debe indicar el motivo de la multa.'
            );
        }

        $existingFine = LibraryFine::query()
            ->where(
                'loan_id',
                new ObjectId((string) $loan->id)
            )
            ->where('type', $type)
            ->where('status', 'pending')
            ->first();

        if ($existingFine !== null) {
            throw new RuntimeException(
                'Ya existe una multa pendiente de este tipo para el préstamo.'
            );
        }

        return LibraryFine::create([
            'folio' => 'MUL-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
            'student_id' => $loan->student_id,

            'loan_id' => $loan->id,

            'type' => $type,

            'amount_cents' => $amountCents,

            'reason' => trim($reason),

            'status' => 'pending',

            'generated_at' => now(),

            'payment_reference_id' => null,

            'paid_at' => null,

            'notes' => $notes,
        ]);
    }

    public function markAsPaid(
        LibraryFine $fine,
        string $paymentReferenceId
    ): LibraryFine {
        $reference = trim($paymentReferenceId);
        $fine->refresh();
        if ($reference !== '' && $fine->status === 'paid' && $fine->verified_payment_id === $reference) {
            return $fine;
        }
        if ($reference === '' || $fine->status !== 'pending') {
            throw new RuntimeException('Se requiere una multa pendiente y una referencia de pago.');
        }

        return $this->recordPayment($fine, $reference, $this->payments->lookupFinePayment((string) $fine->id, $reference));
    }

    private function recordPayment(LibraryFine $fine, string $reference, array $payment): LibraryFine
    {
        if (($payment['status'] ?? null) !== 'CONFIRMED' || ($payment['payment_id'] ?? null) !== $reference
            || ($payment['student_id'] ?? null) !== $fine->student_id || ($payment['currency'] ?? null) !== 'MXN'
            || ($payment['amount_cents'] ?? null) !== $fine->amount_cents || ($payment['concept'] ?? null) !== 'library_fine'
            || ($payment['source_reference'] ?? null) !== (string) $fine->id) {
            throw new RuntimeException('Finanzas no confirmó un pago que coincida con la multa.');
        }

        try {
            $updated = LibraryFine::whereKey($fine->getKey())->where('status', 'pending')
                ->where('student_id', $fine->student_id)->where('amount_cents', $fine->amount_cents)->update([
                    'status' => 'paid',
                    'payment_reference_id' => $reference,
                    'verified_payment_id' => $reference,
                    'paid_at' => now(),
                ]);
        } catch (UniqueConstraintViolationException|BulkWriteException $e) {
            if ($e instanceof BulkWriteException && $e->getCode() !== 11000) {
                throw $e;
            }
            throw new RuntimeException('El pago ya está asociado a otra multa.', 0, $e);
        }

        $fine->refresh();
        if ($updated === 0 && ($fine->status !== 'paid' || $fine->verified_payment_id !== $reference)) {
            throw new RuntimeException('La multa cambió durante la verificación del pago.');
        }

        return $fine;
    }

    public function waiveFine(LibraryFine $fine, ?string $notes = null): LibraryFine
    {
        return $this->closeFine($fine, 'waived', $notes);
    }

    public function cancelFine(LibraryFine $fine, ?string $notes = null): LibraryFine
    {
        return $this->closeFine($fine, 'cancelled', $notes);
    }

    private function closeFine(LibraryFine $fine, string $status, ?string $notes): LibraryFine
    {
        $changes = ['status' => $status, 'notes' => $notes ?? $fine->notes];
        $pending = LibraryFine::whereKey($fine->getKey())->where('status', 'pending');
        // Either closing locally or marking a financial attempt wins the first Mongo write.
        $updated = (clone $pending)->where('finance_requested', '!=', true)->update($changes);
        if (! $updated) {
            $fine->refresh();
            if ($fine->status === $status || ($fine->status === 'paid' && $fine->finance_requested && $fine->verified_payment_id)) {
                return $fine;
            }
            if ($fine->status !== 'pending' || ! $fine->finance_requested) {
                throw new RuntimeException('Solo una multa pendiente puede cerrarse.');
            }
            $result = $this->finance->cancel($fine);
            if ($result['status'] === 'CONFIRMED') {
                return $this->recordPayment($fine, $result['payment_id'], $result);
            }
            if ($result['status'] !== 'CANCELLED') {
                throw new PaymentException('Finanzas aún no confirmó el cierre de la solicitud.');
            }

            $updated = $pending->update($changes);
            $fine->refresh();
            if (! $updated) {
                if ($fine->status === $status) {
                    return $fine;
                }

                throw new RuntimeException('La multa cambió durante el cierre.');
            }
        }

        app(ServiceAuditor::class)->record(
            'library.fine.'.$status,
            'library_fine',
            (string) $fine->id,
            ['status' => 'pending', 'amount_cents' => $fine->amount_cents],
            ['status' => $status],
            $notes
        );

        return $fine->fresh();
    }

    /**
     * Bloqueo de biblioteca (5.2): con una multa pendiente el alumno no puede
     * pedir, renovar ni reservar. Pagarla, condonarla o cancelarla lo libera.
     *
     * @throws RuntimeException
     */
    public function ensureNoPendingFines(
        string $studentId
    ): void {
        if ($this->hasPendingFines(trim($studentId))) {
            throw new RuntimeException(
                'El alumno tiene multas pendientes. Debe pagarlas o resolverlas antes de usar la biblioteca.'
            );
        }
    }

    public function hasPendingFines(
        string $studentId
    ): bool {
        return LibraryFine::query()
            ->where(
                'student_id',
                $studentId
            )
            ->where(
                'status',
                'pending'
            )
            ->exists();
    }
}
