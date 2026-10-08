<?php

namespace App\Services\StudentServices\Calendars;

use App\Models\StudentServices\Calendars\CalendarBlock;
use App\Models\StudentServices\Calendars\ResourceCalendar;
use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;
use RuntimeException;

/**
 * Modulo 5.10 - Administración de cupos, reglas y bloqueos.
 */
class CalendarSettingsService
{
    public function __construct(
        private AvailabilityService $availability,
        private BookingService $bookings,
        private CalendarRuleChecker $checker
    ) {}

    /**
     * Guarda capacidad (en el recurso) y reglas (en su calendario).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException
     */
    public function updateRules(
        string $type,
        Model $resource,
        array $data,
        ?string $updatedBy = null
    ): ResourceCalendar {
        $calendar = $this->availability->calendarFor($type, $resource);

        $rules = [
            ...$calendar->rules(),
            ...array_intersect_key($data, $calendar->rules()),
        ];

        $rules['operating_days'] = array_values(array_unique(array_map('intval', (array) $rules['operating_days'])));
        sort($rules['operating_days']);

        $violations = $this->checker->ruleViolations($rules);

        if ($violations !== []) {
            throw new RuntimeException($violations[0]);
        }

        if (array_key_exists('capacity', $data)) {
            $capacity = (int) $data['capacity'];

            if ($capacity < 1) {
                throw new RuntimeException('La capacidad debe ser al menos 1.');
            }

            $resource->update(['capacity' => $capacity]);
        }

        $calendar->fill([
            ...$rules,
            'resource_type' => $type,
            'resource_id' => (string) $resource->getKey(),
            'updated_by' => $updatedBy,
        ]);

        $calendar->save();

        return $calendar->fresh();
    }

    /**
     * Crea un bloqueo y cancela las reservas confirmadas o en espera que
     * caigan dentro de él (las que ya están en uso se respetan).
     *
     * @return array{block: CalendarBlock, cancelled: int}
     *
     * @throws RuntimeException
     */
    public function createBlock(
        string $type,
        Model $resource,
        CarbonInterface $start,
        CarbonInterface $end,
        string $reason,
        ?string $createdBy = null
    ): array {
        if ($end->lessThanOrEqualTo($start)) {
            throw new RuntimeException('La hora de fin del bloqueo debe ser posterior a la de inicio.');
        }

        if ($end->lessThanOrEqualTo(now())) {
            throw new RuntimeException('No tiene sentido bloquear un horario que ya pasó.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Indica el motivo del bloqueo.');
        }

        $affected = $this->availability->bookingsQuery($type, (string) $resource->getKey())
            ->whereIn('status', [BookingStatus::CONFIRMED, BookingStatus::WAITLISTED])
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->get();

        $block = CalendarBlock::create([
            'folio' => $this->bookings->newFolio('BLQ'),
            'resource_type' => $type,
            'resource_id' => $resource->getKey(),
            'start_at' => $start,
            'end_at' => $end,
            'reason' => trim($reason),
            'created_by' => $createdBy,
            'cancelled_bookings' => $affected->count(),
        ]);

        foreach ($affected as $booking) {
            $this->bookings->cancel($type, $booking, 'system', 'Bloqueo de calendario: '.trim($reason));
        }

        return [
            'block' => $block,
            'cancelled' => $affected->count(),
        ];
    }

    public function deleteBlock(CalendarBlock $block): void
    {
        $block->delete();
    }

    public function findBlock(string $blockId): ?CalendarBlock
    {
        if (preg_match('/^[a-f0-9]{24}$/i', $blockId) !== 1) {
            return null;
        }

        return CalendarBlock::query()->where('_id', new ObjectId($blockId))->first();
    }
}
