<?php

namespace App\Http\Controllers\StudentServices\Library;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Library\AssignBookReservationRequest;
use App\Http\Requests\StudentServices\Library\CancelBookReservationRequest;
use App\Http\Requests\StudentServices\Library\FulfillBookReservationRequest;
use App\Http\Requests\StudentServices\Library\StoreBookReservationRequest;
use App\Models\StudentServices\Library\Book;
use App\Models\StudentServices\Library\BookCopy;
use App\Models\StudentServices\Library\BookReservation;
use App\Services\StudentServices\Library\LoanService;
use App\Services\StudentServices\Library\ReservationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Throwable;

class BookReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService,
        private readonly LoanService $loanService
    ) {
    }

    public function index(): Response
    {
        $this->expireReadyReservations();

        $reservations = BookReservation::query()
            ->orderBy('reserved_at', 'desc')
            ->get()
            ->map(
                fn (BookReservation $reservation) =>
                $this->reservationPayload($reservation)
            )
            ->values();

        $books = Book::query()
            ->get()
            ->map(function (Book $book) {
                $availableCopies = BookCopy::query()
                    ->where(
                        'book_id',
                        new ObjectId(
                            (string) $book->id
                        )
                    )
                    ->where(
                        'status',
                        'available'
                    )
                    ->count();

                return [
                    'id' =>
                        (string) $book->id,

                    'title' =>
                        $book->title,

                    'isbn' =>
                        $book->isbn ?? null,

                    'available_copies' =>
                        $availableCopies,
                ];
            })
            ->values();

        return Inertia::render(
            'student-services/library/BookReservations',
            [
                'reservations' =>
                    $reservations,

                'books' =>
                    $books,
            ]
        );
    }

    public function store(
        StoreBookReservationRequest $request
    ): RedirectResponse {
        $data = $request->validated();

        try {
            $book = Book::findOrFail(
                $data['book_id']
            );

            $this->reservationService
                ->createReservation(
                    $book,
                    trim(
                        $data['student_id']
                    ),
                    $data['notes'] ?? null
                );

            return back()->with(
                'success',
                'Reserva registrada correctamente.'
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors([
                    'reservation' =>
                        $exception->getMessage(),
                ])
                ->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'reservation' =>
                        'No fue posible registrar la reserva.',
                ])
                ->withInput();
        }
    }

    public function assign(
        AssignBookReservationRequest $request,
        string $reservationId
    ): RedirectResponse {
        try {
            $reservation =
                BookReservation::findOrFail(
                    $reservationId
                );

            $data =
                $request->validated();

            $this->reservationService
                ->assignAvailableCopy(
                    $reservation,
                    $data['pickup_hours'] ?? 24
                );

            return back()->with(
                'success',
                'Ejemplar asignado correctamente.'
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors([
                    'reservation' =>
                        $exception->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'reservation' =>
                        'No fue posible asignar el ejemplar.',
                ]);
        }
    }

    public function fulfill(
        FulfillBookReservationRequest $request,
        string $reservationId
    ): RedirectResponse {
        try {
            $reservation =
                BookReservation::findOrFail(
                    $reservationId
                );

            $data =
                $request->validated();

            $this->reservationService
                ->fulfillReservation(
                    $reservation,
                    $this->loanService,
                    $data['loan_days'] ?? 7
                );

            return back()->with(
                'success',
                'Reserva completada y préstamo generado.'
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors([
                    'reservation' =>
                        $exception->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'reservation' =>
                        'No fue posible completar la reserva.',
                ]);
        }
    }

    public function cancel(
        CancelBookReservationRequest $request,
        string $reservationId
    ): RedirectResponse {
        try {
            $reservation =
                BookReservation::findOrFail(
                    $reservationId
                );

            $data =
                $request->validated();

            $this->reservationService
                ->cancelReservation(
                    $reservation,
                    $data['notes'] ?? null
                );

            return back()->with(
                'success',
                'Reserva cancelada correctamente.'
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors([
                    'reservation' =>
                        $exception->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'reservation' =>
                        'No fue posible cancelar la reserva.',
                ]);
        }
    }

    public function expire(
        string $reservationId
    ): RedirectResponse {
        try {
            $reservation =
                BookReservation::findOrFail(
                    $reservationId
                );

            $this->reservationService
                ->expireReservation(
                    $reservation
                );

            return back()->with(
                'success',
                'Reserva marcada como expirada.'
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors([
                    'reservation' =>
                        $exception->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'reservation' =>
                        'No fue posible expirar la reserva.',
                ]);
        }
    }

    private function expireReadyReservations(): void
    {
        $reservations =
            BookReservation::query()
                ->where(
                    'status',
                    'ready'
                )
                ->get();

        foreach (
            $reservations as $reservation
        ) {
            if (
                $reservation->expires_at !== null &&
                now()->greaterThanOrEqualTo(
                    $reservation->expires_at
                )
            ) {
                try {
                    $this->reservationService
                        ->expireReservation(
                            $reservation
                        );
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }

    private function reservationPayload(
        BookReservation $reservation
    ): array {
        $book = Book::find(
            (string) $reservation->book_id
        );

        $copy = null;

        if (
            $reservation->assigned_copy_id !== null
        ) {
            $copy = BookCopy::find(
                (string)
                $reservation
                    ->assigned_copy_id
            );
        }

        return [
            'id' =>
                (string) $reservation->id,

            'book_id' =>
                (string)
                $reservation->book_id,

            'book_title' =>
                $book?->title ??
                'Libro no disponible',

            'student_id' =>
                $reservation->student_id,

            'assigned_copy_id' =>
                $reservation->assigned_copy_id
                    ? (string)
                $reservation
                    ->assigned_copy_id
                    : null,

            'assigned_copy_code' =>
                $copy?->code ??
                    $copy?->inventory_code ??
                    $copy?->barcode ??
                    null,

            'reserved_at' =>
                $reservation->reserved_at
                    ?->toISOString(),

            'ready_at' =>
                $reservation->ready_at
                    ?->toISOString(),

            'expires_at' =>
                $reservation->expires_at
                    ?->toISOString(),

            'fulfilled_at' =>
                $reservation->fulfilled_at
                    ?->toISOString(),

            'status' =>
                $reservation->status,

            'notes' =>
                $reservation->notes,
        ];
    }
}
