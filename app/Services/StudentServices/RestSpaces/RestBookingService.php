<?php

namespace App\Services\StudentServices\RestSpaces;

use App\Models\StudentServices\RestSpaces\RestBooking;
use App\Models\StudentServices\RestSpaces\RestSpace;
use App\Services\StudentServices\Calendars\AvailabilityService;
use App\Services\StudentServices\Calendars\BookableResources;
use App\Services\StudentServices\Calendars\BookingService;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Modulo 5.6 - Reservación de zonas de descanso.
 *
 * Franjas, tiempo máximo, cupo, lista de espera, cancelación y no-show
 * los resuelve el motor de calendarios (5.10). El control de uso
 * (entrada/salida) se registra desde la validación de acceso (5.11).
 */
class RestBookingService
{
    public function __construct(
        private BookingService $bookings,
        private AvailabilityService $availability
    ) {}

    /**
     * @throws RuntimeException
     */
    public function reserve(
        RestSpace $space,
        string $studentId,
        CarbonInterface $start,
        CarbonInterface $end,
        string $idempotencyKey
    ): RestBooking {
        if (! ($space->active ?? true) || $space->status === 'maintenance') {
            throw new RuntimeException('Este espacio no está disponible para reservar.');
        }

        /** @var RestBooking $booking */
        $booking = $this->bookings->reserve(
            BookableResources::REST_SPACE,
            $space,
            $studentId,
            $start,
            $end,
            $idempotencyKey
        );

        return $booking;
    }

    /**
     * @throws RuntimeException
     */
    public function cancel(RestBooking $booking, string $actor = 'student'): void
    {
        $this->bookings->cancel(BookableResources::REST_SPACE, $booking, $actor);
    }

    /**
     * Estado en vivo: mantenimiento, ocupado (cupo lleno en este momento)
     * o disponible.
     */
    public function liveStatus(RestSpace $space): string
    {
        if (! ($space->active ?? true) || $space->status === 'maintenance') {
            return 'maintenance';
        }

        $now = now();

        $occupied = $this->availability->peakOccupancy(
            BookableResources::REST_SPACE,
            $space,
            $now,
            $now->copy()->addMinute()
        );

        return $occupied >= (int) $space->capacity ? 'occupied' : 'available';
    }
}
