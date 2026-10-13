<?php

namespace App\Http\Controllers\StudentServices\Library;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Library\CancelLibraryFineRequest;
use App\Http\Requests\StudentServices\Library\PayLibraryFineRequest;
use App\Http\Requests\StudentServices\Library\StoreLibraryFineRequest;
use App\Http\Requests\StudentServices\Library\WaiveLibraryFineRequest;
use App\Models\StudentServices\Library\Book;
use App\Models\StudentServices\Library\BookCopy;
use App\Models\StudentServices\Library\LibraryFine;
use App\Models\StudentServices\Library\Loan;
use App\Services\StudentServices\Library\FineService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class LibraryFineController extends Controller
{
    public function __construct(
        private readonly FineService $fineService
    ) {}

    public function index(): Response
    {
        $fines = LibraryFine::query()
            ->orderBy(
                'generated_at',
                'desc'
            )
            ->get()
            ->map(
                fn (LibraryFine $fine) => $this->finePayload(
                    $fine
                )
            )
            ->values();

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

        return Inertia::render(
            'student-services/library/Fines',
            [
                'fines' => $fines,

                'loans' => $loans,
            ]
        );
    }

    public function store(
        StoreLibraryFineRequest $request
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $loan =
                Loan::findOrFail(
                    $request->string('loan_id')->value()
                );

            $this->fineService
                ->createFine(
                    $loan,
                    $data['type'],
                    $data['amount_cents'],
                    $data['reason'],
                    $data['notes'] ?? null
                );

            return back()->with(
                'success',
                'Multa registrada correctamente.'
            );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'fine' => $exception
                        ->getMessage(),
                ])
                ->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'fine' => 'No fue posible registrar la multa.',
                ])
                ->withInput();
        }
    }

    public function pay(
        PayLibraryFineRequest $request,
        string $fineId
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $fine =
                LibraryFine::findOrFail(
                    $fineId
                );

            $this->fineService
                ->markAsPaid(
                    $fine,
                    $data[
                    'payment_reference_id'
                    ]
                );

            return back()->with(
                'success',
                'Pago registrado correctamente.'
            );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'fine' => $exception
                        ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'fine' => 'No fue posible registrar el pago.',
                ]);
        }
    }

    public function waive(
        WaiveLibraryFineRequest $request,
        string $fineId
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $fine =
                LibraryFine::findOrFail(
                    $fineId
                );

            $this->fineService
                ->waiveFine(
                    $fine,
                    $data['notes'] ?? null
                );

            return back()->with(
                'success',
                'Multa condonada correctamente.'
            );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'fine' => $exception
                        ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'fine' => 'No fue posible condonar la multa.',
                ]);
        }
    }

    public function cancel(
        CancelLibraryFineRequest $request,
        string $fineId
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $fine =
                LibraryFine::findOrFail(
                    $fineId
                );

            $this->fineService
                ->cancelFine(
                    $fine,
                    $data['notes'] ?? null
                );

            return back()->with(
                'success',
                'Multa cancelada correctamente.'
            );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'fine' => $exception
                        ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'fine' => 'No fue posible cancelar la multa.',
                ]);
        }
    }

    /**
     * @return array{
     *     id: string,
     *     folio: string,
     *     student_id: string,
     *     loan_id: string,
     *     book_title: string,
     *     copy_code: string|null,
     *     type: string,
     *     amount_cents: int,
     *     reason: string,
     *     status: string,
     *     generated_at: string|null,
     *     payment_reference_id: string|null,
     *     paid_at: string|null,
     *     notes: string|null
     * }
     */
    private function finePayload(
        LibraryFine $fine
    ): array {
        $loan = Loan::find(
            (string) $fine->loan_id
        );

        $copy = null;
        $book = null;

        if ($loan !== null) {
            $copy = BookCopy::find(
                (string)
                $loan->copy_id
            );
        }

        if (
            $copy !== null &&
            $copy->book_id !== null
        ) {
            $book = Book::find(
                (string)
                $copy->book_id
            );
        }

        return [
            'id' => (string) $fine->id,

            'folio' => $fine->folio
                ?? 'MUL-'.strtoupper(substr((string) $fine->id, -6)),

            'student_id' => $fine->student_id,

            'loan_id' => (string)
                $fine->loan_id,

            'book_title' => $book->title ??
                'Libro no disponible',

            'copy_code' => $copy->code ??
                    $copy->inventory_code ??
                    $copy->barcode ??
                    null,

            'type' => $fine->type,

            'amount_cents' => $fine->amount_cents,

            'reason' => $fine->reason,

            'status' => $fine->status,

            'generated_at' => $fine->generated_at
                ?->toISOString(),

            'payment_reference_id' => $fine
                ->payment_reference_id,

            'paid_at' => $fine->paid_at
                ?->toISOString(),

            'notes' => $fine->notes,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     student_id: string,
     *     book_title: string,
     *     copy_code: string,
     *     status: string,
     *     borrowed_at: string|null,
     *     due_at: string|null
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
                (string)
                $copy->book_id
            );
        }

        return [
            'id' => (string) $loan->id,

            'student_id' => $loan->student_id,

            'book_title' => $book->title ??
                'Libro no disponible',

            'copy_code' => $copy->code ??
                    $copy->inventory_code ??
                    $copy->barcode ??
                    (string)
                    $loan->copy_id,

            'status' => $loan->status,

            'borrowed_at' => $loan->borrowed_at
                ?->toISOString(),

            'due_at' => $loan->due_at
                ?->toISOString(),
        ];
    }
}
