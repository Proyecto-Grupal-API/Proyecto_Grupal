<?php

namespace App\Models\StudentServices\Calendars;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Modulo 5.10 - Reglas de calendario de un recurso reservable.
 *
 * Un documento por recurso (instalación, zona de descanso...). La
 * capacidad vive en el propio recurso; aquí están horario, franjas,
 * duración, cancelación, no-show y límites por estudiante.
 */
class ResourceCalendar extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'calendars';

    protected $fillable = [
        'resource_type',
        'resource_id',
        'open_time',
        'close_time',
        'slot_minutes',
        'min_booking_minutes',
        'max_booking_minutes',
        'cancel_before_minutes',
        'no_show_tolerance_minutes',
        'max_active_per_student',
        'max_advance_days',
        'max_no_shows',
        'no_show_window_days',
        'operating_days',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'slot_minutes' => 'integer',
            'min_booking_minutes' => 'integer',
            'max_booking_minutes' => 'integer',
            'cancel_before_minutes' => 'integer',
            'no_show_tolerance_minutes' => 'integer',
            'max_active_per_student' => 'integer',
            'max_advance_days' => 'integer',
            'max_no_shows' => 'integer',
            'no_show_window_days' => 'integer',
            'operating_days' => 'array',
        ];
    }

    public function setResourceIdAttribute(mixed $value): void
    {
        $this->attributes['resource_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }

    /**
     * Reglas como arreglo plano para el motor y para el frontend.
     *
     * @return array{
     *     open_time: string,
     *     close_time: string,
     *     slot_minutes: int,
     *     min_booking_minutes: int,
     *     max_booking_minutes: int,
     *     cancel_before_minutes: int,
     *     no_show_tolerance_minutes: int,
     *     max_active_per_student: int,
     *     max_advance_days: int,
     *     max_no_shows: int,
     *     no_show_window_days: int,
     *     operating_days: list<int>
     * }
     */
    public function rules(): array
    {
        return [
            'open_time' => (string) $this->open_time,
            'close_time' => (string) $this->close_time,
            'slot_minutes' => (int) $this->slot_minutes,
            'min_booking_minutes' => (int) $this->min_booking_minutes,
            'max_booking_minutes' => (int) $this->max_booking_minutes,
            'cancel_before_minutes' => (int) $this->cancel_before_minutes,
            'no_show_tolerance_minutes' => (int) $this->no_show_tolerance_minutes,
            'max_active_per_student' => (int) $this->max_active_per_student,
            'max_advance_days' => (int) $this->max_advance_days,
            'max_no_shows' => (int) $this->max_no_shows,
            'no_show_window_days' => (int) $this->no_show_window_days,
            'operating_days' => array_values(array_map('intval', (array) $this->operating_days)),
        ];
    }
}
