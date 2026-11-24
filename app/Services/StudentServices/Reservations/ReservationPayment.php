<?php

namespace App\Services\StudentServices\Reservations;

use App\Models\StudentServices\Reservations\Reservation;
use App\Services\StudentServices\Calendars\BookingStatus;

/**
 * Estado de pago de una reserva de instalación.
 *
 * Servicios no cobra reservas: el contrato de cobros con Finanzas sólo cubre renta de lockers e impresiones. Mientras no
 * exista ese cobro, una reserva de una instalación con costo queda con el pago pendiente y nunca se presenta como
 * confirmada ni como pagada. Aquí no hay tarifas, devoluciones ni reglas nuevas: sólo se dice lo que ocurrió.
 */
final class ReservationPayment
{
    public const NOT_REQUIRED = 'not_required';

    public const PENDING = 'pending';

    public const PENDING_LABEL = 'Apartada · pago pendiente';

    /** Costo de la reserva: el que se guardó al hacerla o, en las anteriores a este dato, el de su instalación. */
    public static function costCents(Reservation $reservation, ?int $facilityCostCents): int
    {
        return max(0, (int) ($reservation->cost_cents ?? $facilityCostCents ?? 0));
    }

    public static function status(Reservation $reservation, ?int $facilityCostCents): string
    {
        return self::forCost(self::costCents($reservation, $facilityCostCents));
    }

    public static function forCost(int $costCents): string
    {
        return $costCents > 0 ? self::PENDING : self::NOT_REQUIRED;
    }

    /** Etiqueta visible del estado: una reserva vigente con costo no dice «Confirmada». */
    public static function label(Reservation $reservation, ?int $facilityCostCents): string
    {
        if ($reservation->status === BookingStatus::CONFIRMED
            && self::status($reservation, $facilityCostCents) === self::PENDING) {
            return self::PENDING_LABEL;
        }

        return BookingStatus::labels()[$reservation->status] ?? (string) $reservation->status;
    }

    /** Aviso al registrar la reserva. */
    public static function announcement(Reservation $reservation, ?int $facilityCostCents): string
    {
        if ($reservation->status !== BookingStatus::CONFIRMED) {
            return 'Sin cupo por ahora: quedaste en lista de espera.';
        }

        $cost = self::costCents($reservation, $facilityCostCents);

        return $cost > 0
            ? 'Reserva apartada con pago pendiente de $'.number_format($cost / 100, 2).' MXN. No se ha realizado ningún cobro: Servicios todavía no cobra reservas en línea.'
            : 'Reserva confirmada.';
    }
}
