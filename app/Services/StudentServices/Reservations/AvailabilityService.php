<?php

namespace App\Services\StudentServices\Reservations;

use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\Reservations\Reservation;
use Carbon\Carbon;
use MongoDB\BSON\ObjectId;

/**
 * Modulo 5.10 - Calendarios, cupos y reglas.
 *
 * Motor de disponibilidad: resuelve si un recurso tiene cupo en una
 * franja dada, contando las reservas CONFIRMADAS que se traslapan con
 * ella. Otros dominios de Servicios al Estudiante (lockers, renta de
 * equipos, biblioteca) pueden seguir este mismo patrón en vez de
 * reimplementar su propia lógica de horarios y cupos.
 */
class AvailabilityService
{
    public function confirmedOverlapping(
        Facility $facility,
        Carbon $start,
        Carbon $end
    ): int {
        return Reservation::query()
            ->where(
                'facility_id',
                new ObjectId((string) $facility->id)
            )
            ->where('status', 'confirmed')
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->count();
    }

    public function isAvailable(
        Facility $facility,
        Carbon $start,
        Carbon $end
    ): bool {
        return $this->confirmedOverlapping($facility, $start, $end)
            < $facility->capacity;
    }

    /**
     * Entre las reservas confirmadas que traslapan la franja solicitada,
     * la que termina más pronto — esa es la hora en la que se liberaría
     * el siguiente cupo disponible.
     */
    public function earliestFreeAt(
        Facility $facility,
        Carbon $start,
        Carbon $end
    ): ?Carbon {
        $reservation = Reservation::query()
            ->where(
                'facility_id',
                new ObjectId((string) $facility->id)
            )
            ->where('status', 'confirmed')
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->orderBy('end_at')
            ->first();

        return $reservation?->end_at;
    }
}
