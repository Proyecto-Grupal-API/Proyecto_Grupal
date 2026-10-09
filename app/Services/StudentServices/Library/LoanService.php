<?php

namespace App\Services\StudentServices\Library;

use App\Models\StudentServices\Library\BookCopy;
use App\Models\StudentServices\Library\Loan;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Throwable;

class LoanService
{
    public const MAX_RENEWALS = 2;

    public const DEFAULT_LOAN_DAYS = 7;

    public function createLoan(
        BookCopy $copy,
        string $studentId,
        int $loanDays = self::DEFAULT_LOAN_DAYS,
        ?string $notes = null
    ): Loan {
        if ($copy->status !== 'available') {
            throw new RuntimeException(
                'El ejemplar no está disponible para préstamo.'
            );
        }

        return $this->createLoanRecord(
            $copy,
            $studentId,
            $loanDays,
            $notes
        );
    }

    public function createLoanFromReservation(
        BookCopy $copy,
        string $studentId,
        int $loanDays = self::DEFAULT_LOAN_DAYS,
        ?string $notes = null
    ): Loan {
        if ($copy->status !== 'reserved') {
            throw new RuntimeException(
                'El ejemplar no está reservado.'
            );
        }

        return $this->createLoanRecord(
            $copy,
            $studentId,
            $loanDays,
            $notes
        );
    }

    private function createLoanRecord(
        BookCopy $copy,
        string $studentId,
        int $loanDays,
        ?string $notes
    ): Loan {
        if ($loanDays < 1 || $loanDays > 30) {
            throw new RuntimeException(
                'Los días de préstamo deben estar entre 1 y 30.'
            );
        }

        $studentId = trim($studentId);

        if ($studentId === '') {
            throw new RuntimeException(
                'El identificador del estudiante es obligatorio.'
            );
        }

        $existingLoan = Loan::query()
            ->where(
                'copy_id',
                new ObjectId((string) $copy->id)
            )
            ->whereIn(
                'status',
                ['active', 'overdue']
            )
            ->first();

        if ($existingLoan !== null) {
            throw new RuntimeException(
                'El ejemplar ya tiene un préstamo activo.'
            );
        }

        $loan = null;

        try {
            $loan = Loan::create([
                'copy_id' => $copy->id,
                'student_id' => $studentId,
                'borrowed_at' => now(),
                'due_at' => now()->addDays(
                    $loanDays
                ),
                'returned_at' => null,
                'status' => 'active',
                'renewal_count' => 0,
                'notes' => $notes,
            ]);

            $copy->update([
                'status' => 'loaned',
            ]);

            return $loan->fresh();
        } catch (Throwable $exception) {
            if ($loan !== null) {
                $loan->delete();
            }

            throw $exception;
        }
    }

    public function returnLoan(
        Loan $loan,
        ?string $notes = null
    ): Loan {
        $this->refreshLoanStatus($loan);

        if (
            !in_array(
                $loan->status,
                ['active', 'overdue'],
                true
            )
        ) {
            throw new RuntimeException(
                'Este préstamo ya no puede ser devuelto.'
            );
        }

        $copy = BookCopy::find(
            (string) $loan->copy_id
        );

        if ($copy === null) {
            throw new RuntimeException(
                'No se encontró el ejemplar asociado al préstamo.'
            );
        }

        $previousStatus =
            $loan->status;

        $previousReturnedAt =
            $loan->returned_at;

        $previousNotes =
            $loan->notes;

        try {
            $loan->update([
                'returned_at' => now(),
                'status' => 'returned',
                'notes' =>
                    $notes ??
                    $loan->notes,
            ]);

            $copy->update([
                'status' => 'available',
            ]);

            return $loan->fresh();
        } catch (Throwable $exception) {
            $loan->update([
                'returned_at' =>
                    $previousReturnedAt,
                'status' =>
                    $previousStatus,
                'notes' =>
                    $previousNotes,
            ]);

            throw $exception;
        }
    }

    public function renewLoan(
        Loan $loan,
        int $additionalDays = self::DEFAULT_LOAN_DAYS
    ): Loan {
        $this->refreshLoanStatus($loan);

        if ($loan->status !== 'active') {
            throw new RuntimeException(
                'Solo se pueden renovar préstamos activos y no vencidos.'
            );
        }

        if (
            $loan->renewal_count >=
            self::MAX_RENEWALS
        ) {
            throw new RuntimeException(
                'El préstamo alcanzó el máximo de renovaciones.'
            );
        }

        if (
            $additionalDays < 1 ||
            $additionalDays > 30
        ) {
            throw new RuntimeException(
                'Los días adicionales deben estar entre 1 y 30.'
            );
        }

        $newDueDate =
            $loan->due_at
                ->copy()
                ->addDays(
                    $additionalDays
                );

        $loan->update([
            'due_at' => $newDueDate,
            'renewal_count' =>
                $loan->renewal_count + 1,
        ]);

        return $loan->fresh();
    }

    public function refreshLoanStatus(
        Loan $loan
    ): Loan {
        if (
            $loan->status === 'active' &&
            $loan->due_at !== null &&
            now()->greaterThan(
                $loan->due_at
            )
        ) {
            $loan->update([
                'status' => 'overdue',
            ]);

            return $loan->fresh();
        }

        return $loan;
    }

    public function refreshOverdueLoans(): void
    {
        $loans = Loan::query()
            ->where('status', 'active')
            ->get();

        foreach ($loans as $loan) {
            $this->refreshLoanStatus(
                $loan
            );
        }
    }
}
