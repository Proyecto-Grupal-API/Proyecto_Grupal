<?php

namespace App\Services\StudentServices\Calendars;

use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\Reservations\Reservation;
use App\Models\StudentServices\RestSpaces\RestBooking;
use App\Models\StudentServices\RestSpaces\RestSpace;
use InvalidArgumentException;

/**
 * Modulo 5.10 - Registro de recursos que usan el motor de calendarios.
 *
 * Cada tipo de recurso declara qué modelo representa al recurso, qué
 * modelo guarda sus reservas, el campo que los relaciona y los valores
 * por defecto de sus reglas. Para que un servicio nuevo (por ejemplo,
 * cubículos o canchas con otro catálogo) use el motor, basta con
 * agregarlo aquí.
 */
final class BookableResources
{
    public const FACILITY = 'facility';

    public const REST_SPACE = 'rest_space';

    /**
     * @return array<string, array{
     *     resource: class-string<Facility|RestSpace>,
     *     booking: class-string<Reservation|RestBooking>,
     *     foreign_key: string,
     *     label: string,
     *     folio_prefix: string,
     *     defaults: array<string, mixed>
     * }>
     */
    public static function definitions(): array
    {
        return [
            self::FACILITY => [
                'resource' => Facility::class,
                'booking' => Reservation::class,
                'foreign_key' => 'facility_id',
                'label' => 'Instalación',
                'folio_prefix' => 'RES',
                'defaults' => [
                    'open_time' => '07:30',
                    'close_time' => '18:30',
                    'slot_minutes' => 30,
                    'min_booking_minutes' => 30,
                    'max_booking_minutes' => 180,
                    'cancel_before_minutes' => 60,
                    'no_show_tolerance_minutes' => 15,
                    'max_active_per_student' => 3,
                    'max_advance_days' => 30,
                    'max_no_shows' => 3,
                    'no_show_window_days' => 30,
                    'operating_days' => [1, 2, 3, 4, 5, 6],
                ],
            ],
            self::REST_SPACE => [
                'resource' => RestSpace::class,
                'booking' => RestBooking::class,
                'foreign_key' => 'rest_space_id',
                'label' => 'Zona de descanso',
                'folio_prefix' => 'ZD',
                'defaults' => [
                    'open_time' => '08:00',
                    'close_time' => '20:00',
                    'slot_minutes' => 15,
                    'min_booking_minutes' => 15,
                    'max_booking_minutes' => 60,
                    'cancel_before_minutes' => 10,
                    'no_show_tolerance_minutes' => 10,
                    'max_active_per_student' => 1,
                    'max_advance_days' => 2,
                    'max_no_shows' => 3,
                    'no_show_window_days' => 30,
                    'operating_days' => [1, 2, 3, 4, 5],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array{
     *     resource: class-string<Facility|RestSpace>,
     *     booking: class-string<Reservation|RestBooking>,
     *     foreign_key: string,
     *     label: string,
     *     folio_prefix: string,
     *     defaults: array<string, mixed>
     * }
     */
    public static function definition(string $type): array
    {
        $definitions = self::definitions();

        if (! isset($definitions[$type])) {
            throw new InvalidArgumentException(
                "El tipo de recurso [{$type}] no está registrado en el motor de calendarios."
            );
        }

        return $definitions[$type];
    }

    /**
     * @return class-string<Reservation|RestBooking>
     */
    public static function bookingModel(string $type): string
    {
        return self::definition($type)['booking'];
    }

    /**
     * @return class-string<Facility|RestSpace>
     */
    public static function resourceModel(string $type): string
    {
        return self::definition($type)['resource'];
    }

    public static function foreignKey(string $type): string
    {
        return self::definition($type)['foreign_key'];
    }

    public static function findResource(string $type, string $resourceId): Facility|RestSpace|null
    {
        $model = self::resourceModel($type);

        return $model::find($resourceId);
    }
}
