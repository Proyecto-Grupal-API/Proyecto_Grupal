<?php

namespace App\Http\Controllers\StudentServices\Reservations;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Reservations\StoreReservationRequest;
use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\Reservations\Reservation;
use App\Services\StudentServices\Reservations\FacilityReservationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ReservationController extends Controller
{
    public function __construct(
        private FacilityReservationService $reservations
    ) {}

    public function store(
        StoreReservationRequest $request
    ): RedirectResponse {
        $data = $request->validated();

        $facility = Facility::find($request->string('facility_id')->value());

        if ($facility === null) {
            return back()->withErrors([
                'facility_id' => 'La instalación seleccionada no existe.',
            ]);
        }

        $start = Carbon::parse(
            $data['date'].' '.$data['start_time']
        );
        $end = Carbon::parse(
            $data['date'].' '.$data['end_time']
        );

        try {
            $reservation = $this->reservations->reserve(
                $facility,
                (string) $request->user()->id,
                $start,
                $end,
                $data['idempotency_key']
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'facility_id' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            $reservation->status === 'confirmed'
                ? 'Reserva confirmada.'
                : 'Sin cupo por ahora: quedaste en lista de espera.'
        );
    }

    public function cancel(
        Request $request,
        string $reservationId
    ): RedirectResponse {
        $reservation = Reservation::findOrFail($reservationId);

        if (
            (string) $reservation->student_id
            !== (string) $request->user()->id
        ) {
            abort(403);
        }

        try {
            $this->reservations->cancel($reservation);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'reservation' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Reserva cancelada.');
    }
}
