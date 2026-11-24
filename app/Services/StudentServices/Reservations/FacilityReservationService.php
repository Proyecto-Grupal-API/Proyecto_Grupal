<?php

namespace App\Services\StudentServices\Reservations;

use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\Reservations\Reservation;
use App\Services\StudentServices\Calendars\BookableResources;
use App\Services\StudentServices\Calendars\BookingService;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Modulo 5.5 - Reservación de instalaciones.
 *
 * No implementa lógica propia de horarios ni cupos: delega en el motor
 * de calendarios (5.10) y aquí solo agrega lo propio de una instalación
 * (que esté activa).
 */
class FacilityReservationService
{
    public function __construct(
        private BookingService $bookings
    ) {}

    /**
     * @throws RuntimeException
     */
    public function reserve(
        Facility $facility,
        string $studentId,
        CarbonInterface $start,
        CarbonInterface $end,
        string $idempotencyKey
    ): Reservation {
        if (! $facility->active) {
            throw new RuntimeException(
                'Esta instalación no está disponible para reservar.'
            );
        }

        /** @var Reservation $reservation */
        $reservation = $this->bookings->reserve(
            BookableResources::FACILITY,
            $facility,
            $studentId,
            $start,
            $end,
            $idempotencyKey
        );

        // El costo vigente y el estado de pago quedan en la reserva (una sola vez: la misma solicitud repetida no los cambia).
        if ($reservation->payment_status === null) {
            $cost = max(0, (int) $facility->cost_cents);
            $reservation->update(['cost_cents' => $cost, 'payment_status' => ReservationPayment::forCost($cost)]);
        }

        return $reservation;
    }

    /**
     * Cancela una reserva confirmada o en espera. Si estaba confirmada,
     * libera cupo y promueve automáticamente la lista de espera.
     *
     * @throws RuntimeException
     */
    public function cancel(Reservation $reservation, string $actor = 'student'): void
    {
        $this->bookings->cancel(BookableResources::FACILITY, $reservation, $actor);
    }
}
