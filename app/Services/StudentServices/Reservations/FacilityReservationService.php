<?php

namespace App\Services\StudentServices\Reservations;

use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\Reservations\Reservation;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Modulo 5.5 - Reservación de instalaciones.
 *
 * No implementa lógica propia de horarios/cupos: usa AvailabilityService
 * (5.10) para saber si hay cupo, y aquí solo agrega el significado de
 * negocio propio de una reserva de instalación (folio, idempotencia,
 * cancelación con promoción automática de lista de espera).
 */
class FacilityReservationService
{
    public function __construct(
        private AvailabilityService $availability
    ) {
    }

    public function reserve(
        Facility $facility,
        string $studentId,
        Carbon $start,
        Carbon $end,
        string $idempotencyKey
    ): Reservation {
        $existing = Reservation::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if (!$facility->active) {
            throw new RuntimeException(
                'Esta instalación no está disponible para reservar.'
            );
        }

        if (!$start->isSameDay($end)) {
            throw new RuntimeException(
                'La reserva debe iniciar y terminar el mismo día.'
            );
        }

        if ($start->isPast() && !$start->isToday()) {
            throw new RuntimeException(
                'No puedes reservar en una fecha anterior a hoy.'
            );
        }

        $available = $this->availability->isAvailable(
            $facility,
            $start,
            $end
        );

        return Reservation::create([
            'folio' => 'RES-' . strtoupper(Str::random(8)),
            'facility_id' => $facility->id,
            'student_id' => $studentId,
            'start_at' => $start,
            'end_at' => $end,
            'status' => $available ? 'confirmed' : 'waitlisted',
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Cancela una reserva confirmada o en espera. Si estaba confirmada,
     * libera cupo real y promueve automáticamente a la siguiente en
     * espera que ya quepa en ese hueco.
     */
    public function cancel(Reservation $reservation): void
    {
        if (
            !in_array(
                $reservation->status,
                ['confirmed', 'waitlisted'],
                true
            )
        ) {
            throw new RuntimeException(
                'Esta reserva ya no puede cancelarse.'
            );
        }

        $wasConfirmed = $reservation->status === 'confirmed';

        $reservation->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        if (!$wasConfirmed) {
            // Una reserva en espera nunca estuvo usando capacidad real,
            // así que cancelarla no libera ni promueve nada.
            return;
        }

        $facility = Facility::find(
            (string) $reservation->facility_id
        );

        if ($facility === null) {
            return;
        }

        $candidate = Reservation::query()
            ->where('facility_id', $reservation->facility_id)
            ->where('status', 'waitlisted')
            ->where('start_at', '<', $reservation->end_at)
            ->where('end_at', '>', $reservation->start_at)
            ->orderBy('created_at')
            ->first();

        if ($candidate === null) {
            return;
        }

        $stillFits = $this->availability->isAvailable(
            $facility,
            $candidate->start_at,
            $candidate->end_at
        );

        if ($stillFits) {
            $candidate->update(['status' => 'confirmed']);
        }
    }
}
