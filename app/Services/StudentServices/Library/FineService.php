<?php

namespace App\Services\StudentServices\Library;

use App\Models\StudentServices\Library\LibraryFine;
use App\Models\StudentServices\Library\Loan;
use App\Services\StudentServices\Audit\ServiceAuditor;
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
        if ($fine->status !== 'pending') {
            throw new RuntimeException(
                'Solo una multa pendiente puede registrarse como pagada.'
            );
        }

        $paymentReferenceId =
            trim($paymentReferenceId);

        if ($paymentReferenceId === '') {
            throw new InvalidArgumentException(
                'La referencia del pago es obligatoria.'
            );
        }

        $fine->update([
            'status' => 'paid',

            'payment_reference_id' => $paymentReferenceId,

            'paid_at' => now(),
        ]);

        return $fine->fresh();
    }

    public function waiveFine(
        LibraryFine $fine,
        ?string $notes = null
    ): LibraryFine {
        if ($fine->status !== 'pending') {
            throw new RuntimeException(
                'Solo una multa pendiente puede ser condonada.'
            );
        }

        $fine->update([
            'status' => 'waived',

            'notes' => $notes ?? $fine->notes,
        ]);

        app(ServiceAuditor::class)->record(
            'library.fine.waived',
            'library_fine',
            (string) $fine->id,
            ['status' => 'pending', 'amount_cents' => $fine->amount_cents],
            ['status' => 'waived'],
            $notes
        );

        return $fine->fresh();
    }

    public function cancelFine(
        LibraryFine $fine,
        ?string $notes = null
    ): LibraryFine {
        if ($fine->status !== 'pending') {
            throw new RuntimeException(
                'Solo una multa pendiente puede ser cancelada.'
            );
        }

        $fine->update([
            'status' => 'cancelled',

            'notes' => $notes ?? $fine->notes,
        ]);

        app(ServiceAuditor::class)->record(
            'library.fine.cancelled',
            'library_fine',
            (string) $fine->id,
            ['status' => 'pending', 'amount_cents' => $fine->amount_cents],
            ['status' => 'cancelled'],
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
