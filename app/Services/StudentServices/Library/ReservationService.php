<?php

namespace App\Services\StudentServices\Library;

use App\Models\StudentServices\Library\Book;
use App\Models\StudentServices\Library\BookCopy;
use App\Models\StudentServices\Library\BookReservation;
use App\Models\StudentServices\Library\Loan;
use MongoDB\BSON\ObjectId;
use RuntimeException;

class ReservationService
{
    public function createReservation(
        Book $book,
        string $studentId,
        ?string $notes = null
    ): BookReservation {
        $existingReservation = BookReservation::query()
            ->where('book_id', new ObjectId((string) $book->id))
            ->where('student_id', $studentId)
            ->whereIn('status', ['pending', 'ready'])
            ->first();

        if ($existingReservation !== null) {
            throw new RuntimeException(
                'El estudiante ya tiene una reserva activa para este libro.'
            );
        }

        return BookReservation::create([
            'book_id' => $book->id,
            'student_id' => $studentId,
            'assigned_copy_id' => null,
            'reserved_at' => now(),
            'ready_at' => null,
            'expires_at' => null,
            'fulfilled_at' => null,
            'status' => 'pending',
            'notes' => $notes,
        ]);
    }

    public function assignAvailableCopy(
        BookReservation $reservation,
        int $pickupHours = 24
    ): BookReservation {
        if ($reservation->status !== 'pending') {
            throw new RuntimeException(
                'Solo se puede asignar un ejemplar a una reserva pendiente.'
            );
        }

        $copy = BookCopy::query()
            ->where(
                'book_id',
                new ObjectId((string) $reservation->book_id)
            )
            ->where('status', 'available')
            ->first();

        if ($copy === null) {
            throw new RuntimeException(
                'No hay ejemplares disponibles para esta reserva.'
            );
        }

        $copy->update([
            'status' => 'reserved',
        ]);

        $reservation->update([
            'assigned_copy_id' => $copy->id,
            'ready_at' => now(),
            'expires_at' => now()->addHours($pickupHours),
            'status' => 'ready',
        ]);

        return $reservation->fresh();
    }

    public function fulfillReservation(
        BookReservation $reservation,
        LoanService $loanService,
        int $loanDays = 7
    ): Loan {
        if ($reservation->status !== 'ready') {
            throw new RuntimeException(
                'Solo una reserva lista para recoger puede convertirse en préstamo.'
            );
        }

        if ($reservation->assigned_copy_id === null) {
            throw new RuntimeException(
                'La reserva no tiene un ejemplar asignado.'
            );
        }

        if (
            $reservation->expires_at !== null &&
            now()->greaterThan($reservation->expires_at)
        ) {
            throw new RuntimeException(
                'La reserva ya expiró.'
            );
        }

        $copy = BookCopy::find(
            (string) $reservation->assigned_copy_id
        );

        if ($copy === null) {
            throw new RuntimeException(
                'No se encontró el ejemplar asignado a la reserva.'
            );
        }

        if ($copy->status !== 'reserved') {
            throw new RuntimeException(
                'El ejemplar asignado ya no se encuentra reservado.'
            );
        }

        $loan = $loanService->createLoanFromReservation(
            $copy,
            $reservation->student_id,
            $loanDays,
            'Préstamo generado desde reserva.'
        );

        $reservation->update([
            'status' => 'fulfilled',
            'fulfilled_at' => now(),
        ]);

        return $loan;
    }

    public function cancelReservation(
        BookReservation $reservation,
        ?string $notes = null
    ): BookReservation {
        if (!in_array($reservation->status, ['pending', 'ready'], true)) {
            throw new RuntimeException(
                'Esta reserva ya no puede ser cancelada.'
            );
        }

        $this->releaseAssignedCopy($reservation);

        $reservation->update([
            'status' => 'cancelled',
            'notes' => $notes ?? $reservation->notes,
        ]);

        return $reservation->fresh();
    }

    public function expireReservation(
        BookReservation $reservation
    ): BookReservation {
        if ($reservation->status !== 'ready') {
            throw new RuntimeException(
                'Solo una reserva lista para recoger puede expirar.'
            );
        }

        if (
            $reservation->expires_at === null ||
            now()->lessThan($reservation->expires_at)
        ) {
            throw new RuntimeException(
                'La reserva todavía no ha alcanzado su fecha de expiración.'
            );
        }

        $this->releaseAssignedCopy($reservation);

        $reservation->update([
            'status' => 'expired',
        ]);

        return $reservation->fresh();
    }

    private function releaseAssignedCopy(
        BookReservation $reservation
    ): void {
        if ($reservation->assigned_copy_id === null) {
            return;
        }

        $copy = BookCopy::find(
            (string) $reservation->assigned_copy_id
        );

        if ($copy !== null && $copy->status === 'reserved') {
            $copy->update([
                'status' => 'available',
            ]);
        }
    }
}
