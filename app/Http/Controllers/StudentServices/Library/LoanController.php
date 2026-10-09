<?php

namespace App\Http\Controllers\StudentServices\Library;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Library\RenewLoanRequest;
use App\Http\Requests\StudentServices\Library\ReturnLoanRequest;
use App\Http\Requests\StudentServices\Library\StoreLoanRequest;
use App\Models\StudentServices\Library\Book;
use App\Models\StudentServices\Library\BookCopy;
use App\Models\StudentServices\Library\Loan;
use App\Services\StudentServices\Library\LoanService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class LoanController extends Controller
{
    public function __construct(
        private readonly LoanService $loanService
    ) {}

    public function index(): Response
    {
        $this->loanService
            ->refreshOverdueLoans();

        $loans = Loan::query()
            ->orderBy(
                'borrowed_at',
                'desc'
            )
            ->get()
            ->map(
                fn (Loan $loan) => $this->loanPayload(
                    $loan
                )
            )
            ->values();

        $availableCopies =
            BookCopy::query()
                ->where(
                    'status',
                    'available'
                )
                ->get()
                ->map(
                    fn (BookCopy $copy) => $this->copyPayload(
                        $copy
                    )
                )
                ->values();

        return Inertia::render(
            'student-services/library/Loans',
            [
                'loans' => $loans,

                'availableCopies' => $availableCopies,
            ]
        );
    }

    public function store(
        StoreLoanRequest $request
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $copy =
                BookCopy::findOrFail(
                    $request->string('copy_id')->value()
                );

            $this->loanService
                ->createLoan(
                    $copy,
                    $data['student_id'],
                    $data['loan_days'] ?? 7,
                    $data['notes'] ?? null
                );

            return back()->with(
                'success',
                'Préstamo registrado correctamente.'
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors([
                    'loan' => $exception
                        ->getMessage(),
                ])
                ->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'loan' => 'No fue posible registrar el préstamo.',
                ])
                ->withInput();
        }
    }

    public function renew(
        RenewLoanRequest $request,
        string $loanId
    ): RedirectResponse {
        try {
            $loan =
                Loan::findOrFail(
                    $loanId
                );

            $data =
                $request->validated();

            $this->loanService
                ->renewLoan(
                    $loan,
                    $data[
                    'additional_days'
                    ] ?? 7
                );

            return back()->with(
                'success',
                'Préstamo renovado correctamente.'
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors([
                    'loan' => $exception
                        ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'loan' => 'No fue posible renovar el préstamo.',
                ]);
        }
    }

    public function returnBook(
        ReturnLoanRequest $request,
        string $loanId
    ): RedirectResponse {
        try {
            $loan =
                Loan::findOrFail(
                    $loanId
                );

            $data =
                $request->validated();

            $this->loanService
                ->returnLoan(
                    $loan,
                    $data['notes'] ?? null
                );

            return back()->with(
                'success',
                'Devolución registrada correctamente.'
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors([
                    'loan' => $exception
                        ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'loan' => 'No fue posible registrar la devolución.',
                ]);
        }
    }

    /**
     * @return array{
     *     id: string,
     *     copy_id: string,
     *     copy_code: string,
     *     book_title: string,
     *     student_id: string,
     *     borrowed_at: string|null,
     *     due_at: string|null,
     *     returned_at: string|null,
     *     status: string,
     *     renewal_count: int,
     *     notes: string|null
     * }
     */
    private function loanPayload(
        Loan $loan
    ): array {
        $copy = BookCopy::find(
            (string) $loan->copy_id
        );

        $book = null;

        if (
            $copy !== null &&
            $copy->book_id !== null
        ) {
            $book = Book::find(
                (string) $copy->book_id
            );
        }

        return [
            'id' => (string) $loan->id,

            'copy_id' => (string) $loan->copy_id,

            'copy_code' => $copy->code ??
                    $copy->inventory_code ??
                    $copy->barcode ??
                    (string) $loan->copy_id,

            'book_title' => $book->title ??
                'Libro no disponible',

            'student_id' => $loan->student_id,

            'borrowed_at' => $loan->borrowed_at
                ?->toISOString(),

            'due_at' => $loan->due_at
                ?->toISOString(),

            'returned_at' => $loan->returned_at
                ?->toISOString(),

            'status' => $loan->status,

            'renewal_count' => $loan->renewal_count,

            'notes' => $loan->notes,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     code: string,
     *     book_id: string|null,
     *     book_title: string,
     *     status: string
     * }
     */
    private function copyPayload(
        BookCopy $copy
    ): array {
        $book = null;

        if ($copy->book_id !== null) {
            $book = Book::find(
                (string) $copy->book_id
            );
        }

        return [
            'id' => (string) $copy->id,

            'code' => $copy->code ??
                    $copy->inventory_code ??
                    $copy->barcode ??
                    (string) $copy->id,

            'book_id' => $copy->book_id
                    ? (string) $copy->book_id
                    : null,

            'book_title' => $book->title ??
                'Libro',

            'status' => $copy->status,
        ];
    }
}
