<?php

namespace App\Services\StudentServices\RestSpaces;

use App\Models\StudentServices\RestSpaces\RestSpace;
use App\Services\StudentServices\Audit\ServiceAuditor;
use App\Services\StudentServices\Calendars\AvailabilityService;
use App\Services\StudentServices\Calendars\BookableResources;
use App\Services\StudentServices\Calendars\BookingService;
use App\Services\StudentServices\Calendars\BookingStatus;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Modulo 5.6 - Catálogo de zonas de descanso.
 */
class RestSpaceService
{
    public function __construct(
        private AvailabilityService $availability,
        private BookingService $bookings,
        private ServiceAuditor $auditor
    ) {}

    /**
     * @param  array{code: string, name: string, type: string, location: string, capacity: int, description?: string|null}  $data
     *
     * @throws RuntimeException
     */
    public function create(array $data): RestSpace
    {
        $code = Str::upper(trim($data['code']));

        if (RestSpace::query()->where('code', $code)->exists()) {
            throw new RuntimeException("Ya existe un espacio con el código {$code}.");
        }

        if (! array_key_exists($data['type'], RestSpace::TYPES)) {
            throw new RuntimeException('El tipo de espacio no es válido.');
        }

        return RestSpace::create([
            'code' => $code,
            'name' => trim($data['name']),
            'type' => $data['type'],
            'location' => trim($data['location']),
            'capacity' => (int) $data['capacity'],
            'description' => trim((string) ($data['description'] ?? '')),
            'status' => 'available',
            'active' => true,
        ]);
    }

    /**
     * Pone el espacio en mantenimiento y cancela las reservas próximas.
     *
     * @return int Número de reservas canceladas.
     *
     * @throws RuntimeException
     */
    public function markMaintenance(RestSpace $space): int
    {
        if ($space->status === 'maintenance') {
            throw new RuntimeException('El espacio ya está en mantenimiento.');
        }

        $space->update(['status' => 'maintenance']);

        $affected = $this->availability
            ->bookingsQuery(BookableResources::REST_SPACE, (string) $space->id)
            ->whereIn('status', [BookingStatus::CONFIRMED, BookingStatus::WAITLISTED])
            ->where('end_at', '>', now())
            ->get()
            ->sortBy(fn ($booking): int => $booking->status === BookingStatus::WAITLISTED ? 0 : 1);

        foreach ($affected as $booking) {
            $this->bookings->cancel(
                BookableResources::REST_SPACE,
                $booking,
                'system',
                'El espacio entró a mantenimiento.'
            );
        }

        $this->auditor->record(
            'rest_space.maintenance.started',
            'rest_space',
            (string) $space->id,
            ['status' => 'available'],
            ['status' => 'maintenance', 'cancelled_bookings' => $affected->count()]
        );

        return $affected->count();
    }

    /**
     * @throws RuntimeException
     */
    public function restoreAvailable(RestSpace $space): void
    {
        if ($space->status !== 'maintenance') {
            throw new RuntimeException('El espacio no está en mantenimiento.');
        }

        $space->update(['status' => 'available']);

        $this->auditor->record(
            'rest_space.maintenance.finished',
            'rest_space',
            (string) $space->id,
            ['status' => 'maintenance'],
            ['status' => 'available']
        );
    }
}
