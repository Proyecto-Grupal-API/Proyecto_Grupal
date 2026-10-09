<?php

namespace Database\Seeders;

use App\Models\StudentServices\Calendars\ResourceCalendar;
use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\RestSpaces\RestSpace;
use App\Services\StudentServices\Calendars\BookableResources;
use Illuminate\Database\Seeder;
use MongoDB\BSON\ObjectId;

/**
 * Modulo 5.10 - Reglas iniciales de calendario por recurso. Debe
 * ejecutarse después de FacilitiesSeeder y RestSpacesSeeder.
 */
class CalendarsSeeder extends Seeder
{
    public function run(): void
    {
        $facilityRules = [
            'Sala de estudio 3' => ['open_time' => '07:30', 'close_time' => '18:30', 'slot_minutes' => 30, 'min_booking_minutes' => 30, 'max_booking_minutes' => 120, 'cancel_before_minutes' => 60, 'no_show_tolerance_minutes' => 15],
            'Laboratorio de Redes' => ['open_time' => '08:00', 'close_time' => '18:00', 'slot_minutes' => 60, 'min_booking_minutes' => 60, 'max_booking_minutes' => 180, 'cancel_before_minutes' => 120, 'no_show_tolerance_minutes' => 15],
            'Auditorio Principal' => ['open_time' => '08:00', 'close_time' => '20:00', 'slot_minutes' => 60, 'min_booking_minutes' => 60, 'max_booking_minutes' => 240, 'cancel_before_minutes' => 1440, 'no_show_tolerance_minutes' => 30],
            'Cancha techada' => ['open_time' => '07:00', 'close_time' => '19:00', 'slot_minutes' => 60, 'min_booking_minutes' => 60, 'max_booking_minutes' => 120, 'cancel_before_minutes' => 120, 'no_show_tolerance_minutes' => 15],
        ];

        Facility::query()->get()->each(function (Facility $facility) use ($facilityRules): void {
            $this->seedCalendar(
                BookableResources::FACILITY,
                (string) $facility->id,
                $facilityRules[$facility->name] ?? []
            );
        });

        $maxMinutesByType = [
            'capsule' => 60,
            'silent' => 120,
            'chair' => 45,
        ];

        RestSpace::query()->get()->each(function (RestSpace $space) use ($maxMinutesByType): void {
            $this->seedCalendar(
                BookableResources::REST_SPACE,
                (string) $space->id,
                ['max_booking_minutes' => $maxMinutesByType[$space->type] ?? 60]
            );
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedCalendar(string $type, string $resourceId, array $overrides): void
    {
        $exists = ResourceCalendar::query()
            ->where('resource_type', $type)
            ->where('resource_id', new ObjectId($resourceId))
            ->exists();

        if ($exists) {
            return;
        }

        ResourceCalendar::create([
            ...BookableResources::definition($type)['defaults'],
            ...$overrides,
            'resource_type' => $type,
            'resource_id' => $resourceId,
        ]);
    }
}
