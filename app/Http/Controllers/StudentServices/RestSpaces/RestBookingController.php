<?php

namespace App\Http\Controllers\StudentServices\RestSpaces;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\RestSpaces\StoreRestBookingRequest;
use App\Models\StudentServices\RestSpaces\RestBooking;
use App\Models\StudentServices\RestSpaces\RestSpace;
use App\Services\StudentServices\Calendars\BookingStatus;
use App\Services\StudentServices\RestSpaces\RestBookingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RestBookingController extends Controller
{
    public function __construct(
        private RestBookingService $restBookings
    ) {}

    public function store(StoreRestBookingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $space = RestSpace::find($data['rest_space_id']);

        if ($space === null) {
            return back()->withErrors(['booking' => 'El espacio seleccionado no existe.']);
        }

        $start = Carbon::parse($data['date'].' '.$data['start_time']);
        $end = $start->copy()->addMinutes((int) $data['duration_minutes']);

        try {
            $booking = $this->restBookings->reserve(
                $space,
                (string) $request->user()->getAuthIdentifier(),
                $start,
                $end,
                $data['idempotency_key']
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()]);
        }

        return back()->with(
            'success',
            $booking->status === BookingStatus::CONFIRMED
                ? "Reservación confirmada ({$booking->folio})."
                : "Sin cupo por ahora: quedaste en lista de espera ({$booking->folio})."
        );
    }

    public function cancel(Request $request, string $bookingId): RedirectResponse
    {
        $booking = RestBooking::findOrFail($bookingId);

        if ((string) $booking->student_id !== (string) $request->user()->getAuthIdentifier()) {
            abort(403);
        }

        try {
            $this->restBookings->cancel($booking);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()]);
        }

        return back()->with('success', 'Reservación cancelada.');
    }
}
