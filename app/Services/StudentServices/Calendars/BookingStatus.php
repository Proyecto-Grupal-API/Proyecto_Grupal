<?php

namespace App\Services\StudentServices\Calendars;

/**
 * Modulo 5.10 - Estados comunes de cualquier reserva que use el motor
 * de calendarios (instalaciones 5.5, zonas de descanso 5.6...).
 */
final class BookingStatus
{
    public const CONFIRMED = 'confirmed';

    public const WAITLISTED = 'waitlisted';

    public const CHECKED_IN = 'checked_in';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const NO_SHOW = 'no_show';

    public const EXPIRED = 'expired';

    /**
     * Estados que ocupan capacidad real del recurso.
     *
     * @var list<string>
     */
    public const OCCUPYING = [
        self::CONFIRMED,
        self::CHECKED_IN,
    ];

    /**
     * Estados que el estudiante todavía tiene "vivos".
     *
     * @var list<string>
     */
    public const ACTIVE = [
        self::CONFIRMED,
        self::WAITLISTED,
        self::CHECKED_IN,
    ];

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::CONFIRMED => 'Confirmada',
            self::WAITLISTED => 'En espera',
            self::CHECKED_IN => 'En uso',
            self::COMPLETED => 'Completada',
            self::CANCELLED => 'Cancelada',
            self::NO_SHOW => 'No se presentó',
            self::EXPIRED => 'Expirada',
        ];
    }
}
