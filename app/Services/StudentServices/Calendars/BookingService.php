<?php

namespace App\Services\StudentServices\Calendars;

use App\Models\StudentServices\Calendars\ResourceCalendar;
use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\Reservations\Reservation;
use App\Models\StudentServices\RestSpaces\RestBooking;
use App\Models\StudentServices\RestSpaces\RestSpace;
use App\Services\StudentServices\Audit\ServiceAuditor;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use RuntimeException;

/**
 * Modulo 5.10 - Ciclo de vida común de una reserva.
 *
 * Crear (confirmada o en lista de espera), cancelar respetando la regla
 * de cancelación mínima, check-in / check-out (los usa el módulo 5.11),
 * no-show automático, promoción de la lista de espera y expiración.
 * Instalaciones (5.5) y zonas de descanso (5.6) solo agregan su
 * significado de negocio encima de este servicio.
 */
class BookingService
{
    public function __construct(
        private AvailabilityService $availability,
        private CalendarRuleChecker $checker,
        private ServiceAuditor $auditor
    ) {}

    /**
     * @throws RuntimeException
     */
    public function reserve(
        string $type,
        Facility|RestSpace $resource,
        string $studentId,
        CarbonInterface $start,
        CarbonInterface $end,
        string $idempotencyKey
    ): Reservation|RestBooking {
        $bookingModel = BookableResources::bookingModel($type);

        $existing = $bookingModel::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ((string) $existing->student_id !== $studentId) {
                throw new RuntimeException('La solicitud ya fue utilizada por otro usuario.');
            }

            return $existing;
        }

        if ((int) $resource->capacity < 1) {
            throw new RuntimeException('El recurso no tiene capacidad configurada.');
        }

        $this->availability->assertCanBook($type, $resource, $studentId, $start, $end);

        $available = $this->availability->isAvailable($type, $resource, $start, $end);

        return $bookingModel::create([
            'folio' => $this->newFolio(BookableResources::definition($type)['folio_prefix']),
            BookableResources::foreignKey($type) => $resource->getKey(),
            'student_id' => $studentId,
            'start_at' => $start,
            'end_at' => $end,
            'status' => $available ? BookingStatus::CONFIRMED : BookingStatus::WAITLISTED,
            'waitlisted_at' => $available ? null : now(),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * @param  string  $actor  student, staff o system. Solo el estudiante
     *                         queda sujeto a la regla de cancelación mínima.
     *
     * @throws RuntimeException
     */
    public function cancel(
        string $type,
        Reservation|RestBooking $booking,
        string $actor = 'student',
        ?string $reason = null
    ): void {
        if (! in_array($booking->status, [BookingStatus::CONFIRMED, BookingStatus::WAITLISTED], true)) {
            throw new RuntimeException('Esta reserva ya no puede cancelarse.');
        }

        $resource = $this->resourceOf($type, $booking);
        $wasConfirmed = $booking->status === BookingStatus::CONFIRMED;

        if ($wasConfirmed && $actor === 'student' && $resource !== null) {
            $rules = $this->availability->rulesFor($type, $resource);

            if (! $this->checker->canStudentCancel($rules, $booking->start_at, now())) {
                throw new RuntimeException(
                    "Ya no es posible cancelar: el límite es {$rules['cancel_before_minutes']} minutos antes del inicio."
                );
            }
        }

        $booking->update([
            'status' => BookingStatus::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $actor,
            'cancellation_reason' => $reason,
        ]);

        if ($wasConfirmed && $resource !== null) {
            $this->promoteWaitlist($type, $resource, $booking->start_at, $booking->end_at);
        }
    }

    /**
     * @throws RuntimeException
     */
    public function checkIn(string $type, Reservation|RestBooking $booking): Reservation|RestBooking
    {
        if ($booking->status === BookingStatus::CHECKED_IN) {
            throw new RuntimeException("La reserva {$booking->folio} ya tiene check-in registrado.");
        }

        if ($booking->status !== BookingStatus::CONFIRMED) {
            $label = BookingStatus::labels()[$booking->status] ?? $booking->status;

            throw new RuntimeException("La reserva {$booking->folio} no está confirmada (estado: {$label}).");
        }

        $resource = $this->resourceOf($type, $booking);

        if ($resource === null) {
            throw new RuntimeException('El recurso de la reserva ya no existe.');
        }

        $rules = $this->availability->rulesFor($type, $resource);
        $window = $this->checker->checkInWindow($rules, $booking->start_at, $booking->end_at);
        $now = now();

        if ($now->lessThan($window['opens_at'])) {
            throw new RuntimeException(
                "Aún no inicia la ventana de check-in (a partir de las {$window['opens_at']->format('H:i')} del {$window['opens_at']->format('d/m/Y')})."
            );
        }

        if ($now->greaterThan($window['closes_at'])) {
            $this->markNoShow($type, $booking, $resource);

            throw new RuntimeException(
                "La tolerancia de llegada terminó a las {$window['closes_at']->format('H:i')}; la reserva se marcó como no-show."
            );
        }

        $booking->update([
            'status' => BookingStatus::CHECKED_IN,
            'checked_in_at' => $now,
        ]);

        return $booking->fresh();
    }

    /**
     * @throws RuntimeException
     */
    public function checkOut(string $type, Reservation|RestBooking $booking): Reservation|RestBooking
    {
        if ($booking->status !== BookingStatus::CHECKED_IN) {
            throw new RuntimeException("La reserva {$booking->folio} no tiene un check-in activo.");
        }

        $now = now();

        $booking->update([
            'status' => BookingStatus::COMPLETED,
            'checked_out_at' => $now,
        ]);

        $resource = $this->resourceOf($type, $booking);

        if ($resource !== null && $now->lessThan($booking->end_at)) {
            $this->promoteWaitlist($type, $resource, $now, $booking->end_at);
        }

        return $booking->fresh();
    }

    /**
     * Promoción manual desde el panel 5.10. Solo procede si ya hay cupo.
     *
     * @throws RuntimeException
     */
    public function promote(string $type, Reservation|RestBooking $booking): Reservation|RestBooking
    {
        if ($booking->status !== BookingStatus::WAITLISTED) {
            throw new RuntimeException('Solo se pueden promover reservas en lista de espera.');
        }

        if ($booking->end_at->lessThanOrEqualTo(now())) {
            throw new RuntimeException('El horario de esta solicitud ya pasó.');
        }

        $resource = $this->resourceOf($type, $booking);

        if ($resource === null || ! $this->availability->isAvailable($type, $resource, $booking->start_at, $booking->end_at)) {
            throw new RuntimeException('Aún no hay cupo para esa franja; la solicitud sigue en espera.');
        }

        $booking->update([
            'status' => BookingStatus::CONFIRMED,
            'promoted_at' => now(),
        ]);

        $this->auditor->record(
            'calendar.waitlist.promoted_manually',
            $type.'_booking',
            (string) $booking->getKey(),
            ['status' => BookingStatus::WAITLISTED],
            ['status' => BookingStatus::CONFIRMED, 'student_id' => (string) $booking->student_id]
        );

        return $booking->fresh();
    }

    /**
     * Promueve, en orden de llegada, las solicitudes en espera que ya
     * quepan en la franja liberada.
     */
    public function promoteWaitlist(
        string $type,
        Facility|RestSpace $resource,
        CarbonInterface $start,
        CarbonInterface $end
    ): int {
        $candidates = $this->availability->bookingsQuery($type, (string) $resource->getKey())
            ->where('status', BookingStatus::WAITLISTED)
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->where('start_at', '>', now())
            ->get()
            ->sortBy(fn (Reservation|RestBooking $booking): int => ($booking->waitlisted_at ?? $booking->created_at)?->getTimestamp() ?? 0)
            ->values();

        $promoted = 0;

        foreach ($candidates as $candidate) {
            if ($this->availability->isAvailable($type, $resource, $candidate->start_at, $candidate->end_at)) {
                $candidate->update([
                    'status' => BookingStatus::CONFIRMED,
                    'promoted_at' => now(),
                ]);

                $promoted++;
            }
        }

        return $promoted;
    }

    /**
     * Actualización perezosa de estados (mismo patrón que
     * refreshOverdueLoans): no-show, espera expirada y uso terminado.
     */
    public function refreshStatuses(string $type): void
    {
        $now = now();
        $bookingModel = BookableResources::bookingModel($type);
        $resourceModel = BookableResources::resourceModel($type);
        $foreignKey = BookableResources::foreignKey($type);

        $calendars = ResourceCalendar::query()
            ->where('resource_type', $type)
            ->get()
            ->keyBy(fn (ResourceCalendar $calendar): string => (string) $calendar->resource_id);

        $defaults = BookableResources::definition($type)['defaults'];

        $bookingModel::query()
            ->where('status', BookingStatus::CONFIRMED)
            ->where('start_at', '<=', $now)
            ->get()
            ->each(function (Reservation|RestBooking $booking) use ($calendars, $defaults, $foreignKey, $now, $resourceModel, $type): void {
                $resourceId = (string) $booking->{$foreignKey};
                $rules = $calendars->get($resourceId)?->rules() ?? $defaults;

                if ($this->checker->isNoShow($rules, $booking->start_at, $booking->end_at, $now)) {
                    $this->markNoShow($type, $booking, $resourceModel::find($resourceId));
                }
            });

        $bookingModel::query()
            ->where('status', BookingStatus::WAITLISTED)
            ->where('start_at', '<=', $now)
            ->update([
                'status' => BookingStatus::EXPIRED,
                'expired_at' => $now,
            ]);

        $bookingModel::query()
            ->where('status', BookingStatus::CHECKED_IN)
            ->where('end_at', '<=', $now)
            ->get()
            ->each(fn (Reservation|RestBooking $booking) => $booking->update([
                'status' => BookingStatus::COMPLETED,
                'checked_out_at' => $booking->end_at,
            ]));
    }

    public function newFolio(string $prefix): string
    {
        return $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
    }

    public function resourceOf(string $type, Reservation|RestBooking $booking): Facility|RestSpace|null
    {
        $resourceId = $booking->{BookableResources::foreignKey($type)};

        if ($resourceId === null) {
            return null;
        }

        return BookableResources::findResource($type, (string) $resourceId);
    }

    /**
     * Busca una reserva del tipo indicado por folio o por id.
     */
    public function findBooking(string $type, string $reference): Reservation|RestBooking|null
    {
        $model = BookableResources::bookingModel($type);
        $reference = trim($reference);

        $booking = $model::query()->where('folio', Str::upper($reference))->first();

        if ($booking === null && preg_match('/^[a-f0-9]{24}$/i', $reference) === 1) {
            $booking = $model::query()->where('_id', new ObjectId($reference))->first();
        }

        return $booking;
    }

    private function markNoShow(string $type, Reservation|RestBooking $booking, Facility|RestSpace|null $resource): void
    {
        $now = now();

        $booking->update([
            'status' => BookingStatus::NO_SHOW,
            'no_show_at' => $now,
        ]);

        if ($resource !== null && $now->lessThan($booking->end_at)) {
            $this->promoteWaitlist($type, $resource, $now, $booking->end_at);
        }
    }
}
