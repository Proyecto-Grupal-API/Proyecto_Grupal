<?php

namespace App\Services\StudentServices\Calendars;

use App\Models\StudentServices\Calendars\CalendarBlock;
use App\Models\StudentServices\Calendars\ResourceCalendar;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;
use RuntimeException;

/**
 * Modulo 5.10 - Calendarios, cupos y reglas.
 *
 * Motor común de disponibilidad. Resuelve, para cualquier recurso
 * registrado en BookableResources, si una franja tiene cupo (ocupación
 * máxima simultánea contra la capacidad), si está bloqueada, y si el
 * estudiante cumple las reglas del recurso (horario, franjas, duración,
 * anticipación, límite de reservas activas, traslapes y penalización
 * por inasistencias). Los módulos 5.5 y 5.6 no implementan horarios ni
 * cupos propios: todo pasa por aquí.
 */
class AvailabilityService
{
    public function __construct(
        private CalendarRuleChecker $checker
    ) {}

    /**
     * Calendario guardado del recurso, o uno nuevo (sin guardar) con los
     * valores por defecto de su tipo.
     */
    public function calendarFor(string $type, Model $resource): ResourceCalendar
    {
        $calendar = ResourceCalendar::query()
            ->where('resource_type', $type)
            ->where('resource_id', new ObjectId((string) $resource->getKey()))
            ->first();

        if ($calendar !== null) {
            return $calendar;
        }

        return new ResourceCalendar([
            'resource_type' => $type,
            'resource_id' => (string) $resource->getKey(),
            ...BookableResources::definition($type)['defaults'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rulesFor(string $type, Model $resource): array
    {
        return $this->calendarFor($type, $resource)->rules();
    }

    /**
     * Reglas de todos los recursos de un tipo, indexadas por id.
     *
     * @param  Collection<int, Model>  $resources
     * @return array<string, array<string, mixed>>
     */
    public function rulesForMany(string $type, Collection $resources): array
    {
        $saved = ResourceCalendar::query()
            ->where('resource_type', $type)
            ->get()
            ->keyBy(fn (ResourceCalendar $calendar): string => (string) $calendar->resource_id);

        $defaults = BookableResources::definition($type)['defaults'];

        $rules = [];

        foreach ($resources as $resource) {
            $id = (string) $resource->getKey();

            $calendar = $saved->get($id) ?? new ResourceCalendar([
                'resource_type' => $type,
                'resource_id' => $id,
                ...$defaults,
            ]);

            $rules[$id] = $calendar->rules();
        }

        return $rules;
    }

    public function bookingsQuery(string $type, string $resourceId): Builder
    {
        $model = BookableResources::bookingModel($type);

        return $model::query()->where(
            BookableResources::foreignKey($type),
            new ObjectId($resourceId)
        );
    }

    /**
     * Rangos [inicio, fin] de las reservas que ocupan capacidad y se
     * traslapan con la franja solicitada.
     *
     * @return list<array{0: CarbonInterface, 1: CarbonInterface}>
     */
    public function occupyingRanges(
        string $type,
        Model $resource,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $excludeBookingId = null
    ): array {
        return $this->bookingsQuery($type, (string) $resource->getKey())
            ->whereIn('status', BookingStatus::OCCUPYING)
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->get()
            ->reject(fn (Model $booking): bool => $excludeBookingId !== null && (string) $booking->getKey() === $excludeBookingId)
            ->map(fn (Model $booking): array => [$booking->start_at, $booking->end_at])
            ->values()
            ->all();
    }

    public function peakOccupancy(
        string $type,
        Model $resource,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $excludeBookingId = null
    ): int {
        return $this->checker->peakOccupancy(
            $this->occupyingRanges($type, $resource, $start, $end, $excludeBookingId),
            $start,
            $end
        );
    }

    public function isAvailable(
        string $type,
        Model $resource,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $excludeBookingId = null
    ): bool {
        if ($this->blockFor($type, $resource, $start, $end) !== null) {
            return false;
        }

        return $this->peakOccupancy($type, $resource, $start, $end, $excludeBookingId)
            < (int) $resource->capacity;
    }

    /**
     * Entre las reservas que ocupan la franja solicitada, la que termina
     * más pronto: a esa hora se liberaría el siguiente cupo.
     */
    public function earliestFreeAt(
        string $type,
        Model $resource,
        CarbonInterface $start,
        CarbonInterface $end
    ): ?CarbonInterface {
        $booking = $this->bookingsQuery($type, (string) $resource->getKey())
            ->whereIn('status', BookingStatus::OCCUPYING)
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->orderBy('end_at')
            ->first();

        return $booking?->end_at;
    }

    public function blockFor(
        string $type,
        Model $resource,
        CarbonInterface $start,
        CarbonInterface $end
    ): ?CalendarBlock {
        return CalendarBlock::query()
            ->where('resource_type', $type)
            ->where('resource_id', new ObjectId((string) $resource->getKey()))
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->first();
    }

    /**
     * Valida todas las reglas antes de crear una reserva. No revisa el
     * cupo: si no hay cupo la reserva entra a lista de espera.
     *
     * @throws RuntimeException
     */
    public function assertCanBook(
        string $type,
        Model $resource,
        string $studentId,
        CarbonInterface $start,
        CarbonInterface $end
    ): void {
        $rules = $this->rulesFor($type, $resource);
        $now = now();

        $this->checker->assertValid($rules, $start, $end, $now);

        $block = $this->blockFor($type, $resource, $start, $end);

        if ($block !== null) {
            throw new RuntimeException(
                "Ese horario está bloqueado: {$block->reason} ({$block->start_at->format('H:i')} – {$block->end_at->format('H:i')})."
            );
        }

        $bookingModel = BookableResources::bookingModel($type);

        $recentNoShows = $bookingModel::query()
            ->where('student_id', $studentId)
            ->where('status', BookingStatus::NO_SHOW)
            ->where('no_show_at', '>=', $now->copy()->subDays((int) $rules['no_show_window_days']))
            ->count();

        if ((int) $rules['max_no_shows'] > 0 && $recentNoShows >= (int) $rules['max_no_shows']) {
            throw new RuntimeException(
                "Tienes {$recentNoShows} inasistencias en los últimos {$rules['no_show_window_days']} días; no puedes reservar por ahora."
            );
        }

        $activeBookings = $bookingModel::query()
            ->where('student_id', $studentId)
            ->whereIn('status', BookingStatus::ACTIVE)
            ->where('end_at', '>', $now)
            ->count();

        if ($activeBookings >= (int) $rules['max_active_per_student']) {
            throw new RuntimeException(
                "Alcanzaste el límite de {$rules['max_active_per_student']} reserva(s) activa(s) para este servicio."
            );
        }

        $overlapping = $this->studentOverlap($studentId, $start, $end);

        if ($overlapping !== null) {
            throw new RuntimeException(
                "Ya tienes otra reserva en ese horario (folio {$overlapping->folio})."
            );
        }
    }

    /**
     * Una persona no puede estar en dos lugares a la vez: revisa los
     * traslapes contra TODOS los tipos de recurso registrados.
     */
    public function studentOverlap(
        string $studentId,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $excludeBookingId = null
    ): ?Model {
        foreach (BookableResources::types() as $type) {
            $model = BookableResources::bookingModel($type);

            $booking = $model::query()
                ->where('student_id', $studentId)
                ->whereIn('status', BookingStatus::ACTIVE)
                ->where('start_at', '<', $end)
                ->where('end_at', '>', $start)
                ->get()
                ->first(fn (Model $booking): bool => $excludeBookingId === null || (string) $booking->getKey() !== $excludeBookingId);

            if ($booking !== null) {
                return $booking;
            }
        }

        return null;
    }

    /**
     * Posición (1, 2, 3...) de una reserva dentro de su lista de espera.
     */
    public function waitlistPosition(string $type, Model $booking): int
    {
        if ($booking->status !== BookingStatus::WAITLISTED) {
            return 0;
        }

        $foreignKey = BookableResources::foreignKey($type);
        $joinedAt = $booking->waitlisted_at ?? $booking->created_at;

        $ahead = $this->bookingsQuery($type, (string) $booking->{$foreignKey})
            ->where('status', BookingStatus::WAITLISTED)
            ->where('start_at', '<', $booking->end_at)
            ->where('end_at', '>', $booking->start_at)
            ->get()
            ->filter(function (Model $other) use ($booking, $joinedAt): bool {
                if ((string) $other->getKey() === (string) $booking->getKey()) {
                    return false;
                }

                $otherJoinedAt = $other->waitlisted_at ?? $other->created_at;

                return $otherJoinedAt !== null && $joinedAt !== null && $otherJoinedAt->lessThan($joinedAt);
            })
            ->count();

        return $ahead + 1;
    }

    /**
     * Rangos ocupados (sin datos personales) por recurso, para que el
     * frontend pinte disponibilidad por franja.
     *
     * @param  list<string>  $resourceIds
     * @return array<string, list<array{start_at: string, end_at: string}>>
     */
    public function bookedRanges(
        string $type,
        array $resourceIds,
        CarbonInterface $from,
        CarbonInterface $to
    ): array {
        $model = BookableResources::bookingModel($type);
        $foreignKey = BookableResources::foreignKey($type);

        $ranges = array_fill_keys($resourceIds, []);

        $model::query()
            ->whereIn($foreignKey, array_map(fn (string $id): ObjectId => new ObjectId($id), $resourceIds))
            ->whereIn('status', BookingStatus::OCCUPYING)
            ->where('end_at', '>', $from)
            ->where('start_at', '<', $to)
            ->get()
            ->each(function (Model $booking) use (&$ranges, $foreignKey): void {
                $ranges[(string) $booking->{$foreignKey}][] = [
                    'start_at' => $booking->start_at->toIso8601String(),
                    'end_at' => $booking->end_at->toIso8601String(),
                ];
            });

        return $ranges;
    }

    /**
     * @param  list<string>  $resourceIds
     * @return array<string, list<array{start_at: string, end_at: string, reason: string}>>
     */
    public function blocksByResource(
        string $type,
        array $resourceIds,
        CarbonInterface $from,
        CarbonInterface $to
    ): array {
        $blocks = array_fill_keys($resourceIds, []);

        CalendarBlock::query()
            ->where('resource_type', $type)
            ->where('end_at', '>', $from)
            ->where('start_at', '<', $to)
            ->get()
            ->each(function (CalendarBlock $block) use (&$blocks): void {
                $id = (string) $block->resource_id;

                if (! array_key_exists($id, $blocks)) {
                    return;
                }

                $blocks[$id][] = [
                    'start_at' => $block->start_at->toIso8601String(),
                    'end_at' => $block->end_at->toIso8601String(),
                    'reason' => (string) $block->reason,
                ];
            });

        return $blocks;
    }
}
