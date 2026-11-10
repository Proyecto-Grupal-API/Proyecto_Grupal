<?php

namespace App\Services\StudentServices\Platform;

use App\Models\StudentServices\Benefits\BenefitAssignment;
use App\Models\StudentServices\Library\LibraryFine;
use App\Models\StudentServices\Library\Loan;
use App\Models\StudentServices\Lockers\LockerAssignment;
use App\Models\StudentServices\Rentals\AssetCondition;
use App\Models\StudentServices\Rentals\Rental;
use App\Models\StudentServices\Reservations\Reservation;
use App\Models\StudentServices\RestSpaces\RestBooking;
use App\Models\StudentServices\ServiceAccess\ServiceCheckin;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Models\StudentServices\Support\SupportTicket;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use MongoDB\BSON\ObjectId;
use Throwable;

/**
 * Convierte altas y cambios de estado de los modelos del Equipo 5 en
 * eventos para el Equipo 7, sin tocar la lógica de cada servicio.
 *
 * Los cambios hechos con actualizaciones masivas (liberar o renovar un
 * locker, cancelar un saldo de impresiones) no pasan por aquí: esos
 * servicios emiten su evento de forma explícita.
 */
class ServicePlatformObserver
{
    public const MODELS = [
        Loan::class,
        LibraryFine::class,
        Reservation::class,
        RestBooking::class,
        Rental::class,
        ServiceOrder::class,
        SupportTicket::class,
        LockerAssignment::class,
        BenefitAssignment::class,
        ServiceCheckin::class,
    ];

    /** Estado de reserva => acción publicada. */
    private const BOOKING_TRANSITIONS = [
        'confirmed' => 'promoted',
        'checked_in' => 'attended',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        'no_show' => 'no_show',
        'expired' => 'expired',
    ];

    /** Estado de orden => acción publicada. */
    private const ORDER_TRANSITIONS = [
        'processing' => 'paid',
        'ready' => 'ready',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled',
    ];

    public function __construct(
        private ServicePlatformEvents $events
    ) {}

    public function created(Model $model): void
    {
        try {
            $this->onCreated($model);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function updated(Model $model): void
    {
        try {
            $this->onUpdated($model);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function onCreated(Model $model): void
    {
        if ($model instanceof Loan) {
            $this->emit('loan.created', $model, [
                'student_id' => $model->student_id,
                'loan_id' => $model->id,
                'copy_id' => $model->copy_id,
                'due_at' => $model->due_at,
            ]);
        } elseif ($model instanceof LibraryFine) {
            $this->emit('fine.created', $model, [
                'student_id' => $model->student_id,
                'fine_id' => $model->id,
                'loan_id' => $model->loan_id,
                'type' => $model->type,
                'amount_cents' => (int) $model->amount_cents,
            ]);
        } elseif ($model instanceof Reservation || $model instanceof RestBooking) {
            $this->emit('reservation.created', $model, [
                ...$this->bookingPayload($model),
                'status' => $model->status,
                'end_at' => $model->end_at,
            ]);
        } elseif ($model instanceof Rental) {
            $this->emit('rental.created', $model, [
                'student_id' => $model->student_id,
                'rental_id' => $model->id,
                'asset_id' => $model->asset_id,
                'due_at' => $model->due_at,
                'deposit_cents' => (int) ($model->deposit_cents ?? 0),
            ]);
        } elseif ($model instanceof ServiceOrder) {
            $this->emit('order.created', $model, [
                'student_id' => $model->student_id,
                'order_id' => $model->id,
                'service_type' => $model->service_type,
                'quantity' => (int) $model->quantity,
            ]);
        } elseif ($model instanceof SupportTicket) {
            $this->emit('ticket.created', $model, [
                'student_id' => $model->student_id,
                'ticket_id' => $model->id,
                'category' => $model->category,
                'priority' => $model->priority,
            ]);
        } elseif ($model instanceof LockerAssignment) {
            $this->emit('locker.assigned', $model, [
                'student_id' => $model->student_id,
                'assignment_id' => $model->id,
                'locker_id' => $model->locker_id,
                'period_id' => $model->period_id,
                'source' => $model->source,
                'ends_at' => $model->ends_at,
                // Una renovación cierra la asignación anterior y crea esta.
                'renewed_from_id' => $model->renewed_from_id,
            ]);
        } elseif ($model instanceof BenefitAssignment && $model->status === 'active') {
            $this->benefitAssigned($model);
        } elseif ($model instanceof ServiceCheckin) {
            // Un acceso negado puede no tener alumno identificado (credencial inválida).
            $this->emit($model->granted ? 'access.validated' : 'access.denied', $model, [
                'student_id' => $model->student_id ?? 'unidentified',
                'checkin_id' => $model->id,
                'service' => $model->service,
                'operation' => $model->action,
                'method' => $model->method,
            ]);
        }
    }

    private function onUpdated(Model $model): void
    {
        if ($model instanceof Loan) {
            $this->loanUpdated($model);

            return;
        }

        if ($model instanceof Rental) {
            $this->rentalUpdated($model);

            return;
        }

        if (! $model->wasChanged('status')) {
            return;
        }

        $from = (string) $model->getOriginal('status');
        $to = (string) $model->status;

        if ($model instanceof LibraryFine && in_array($to, ['paid', 'waived', 'cancelled'], true)) {
            $this->emit('fine.'.$to, $model, [
                'student_id' => $model->student_id,
                'fine_id' => $model->id,
                'amount_cents' => (int) $model->amount_cents,
            ]);
        } elseif (($model instanceof Reservation || $model instanceof RestBooking) && isset(self::BOOKING_TRANSITIONS[$to])) {
            // "confirmed" solo cuenta como promoción si venía de la lista de espera.
            if ($to !== 'confirmed' || $from === 'waitlisted') {
                $this->emit('reservation.'.self::BOOKING_TRANSITIONS[$to], $model, $this->bookingPayload($model));
            }
        } elseif ($model instanceof ServiceOrder && isset(self::ORDER_TRANSITIONS[$to])) {
            $this->emit('order.'.self::ORDER_TRANSITIONS[$to], $model, [
                'student_id' => $model->student_id,
                'order_id' => $model->id,
                'service_type' => $model->service_type,
                'payment_status' => $model->payment_status,
            ]);
        } elseif ($model instanceof SupportTicket) {
            $this->emit('ticket.status_changed', $model, [
                'student_id' => $model->student_id,
                'ticket_id' => $model->id,
                'from' => $from,
                'to' => $to,
            ]);
        } elseif ($model instanceof LockerAssignment && $to === 'released') {
            $this->lockerReleased($model);
        } elseif ($model instanceof BenefitAssignment && $to === 'active') {
            $this->benefitAssigned($model);
        } elseif ($model instanceof BenefitAssignment && $to === 'cancelled') {
            $this->benefitCancelled($model);
        }
    }

    private function loanUpdated(Loan $loan): void
    {
        if ($loan->wasChanged('renewal_count') && (int) $loan->renewal_count > (int) $loan->getOriginal('renewal_count')) {
            $this->emit('loan.renewed', $loan, [
                'student_id' => $loan->student_id,
                'loan_id' => $loan->id,
                'due_at' => $loan->due_at,
                'renewal_count' => (int) $loan->renewal_count,
            ]);
        }

        if (! $loan->wasChanged('status')) {
            return;
        }

        if ($loan->status === 'overdue') {
            $this->emit('loan.overdue', $loan, [
                'student_id' => $loan->student_id,
                'loan_id' => $loan->id,
                'due_at' => $loan->due_at,
            ]);

            return;
        }

        if ($loan->status !== 'returned') {
            return;
        }

        $onTime = $this->onTime($loan->returned_at, $loan->due_at);

        $this->emit('loan.returned', $loan, [
            'student_id' => $loan->student_id,
            'loan_id' => $loan->id,
            'copy_id' => $loan->copy_id,
            'due_at' => $loan->due_at,
            'returned_at' => $loan->returned_at,
            'on_time' => $onTime,
        ]);

        // P3: acción premiable, solo cuando la condición ya se cumplió.
        if ($onTime) {
            $this->emit('loan.returned_on_time', $loan, [
                'student_id' => $loan->student_id,
                'loan_id' => $loan->id,
            ]);
        }
    }

    private function rentalUpdated(Rental $rental): void
    {
        if ($rental->wasChanged('picked_up_at') && $rental->getOriginal('picked_up_at') === null && $rental->picked_up_at !== null) {
            $this->emit('rental.picked_up', $rental, [
                'student_id' => $rental->student_id,
                'rental_id' => $rental->id,
                'asset_id' => $rental->asset_id,
            ]);
        }

        if (! $rental->wasChanged('status')) {
            return;
        }

        if (in_array($rental->status, ['cancelled', 'overdue'], true)) {
            $this->emit('rental.'.$rental->status, $rental, [
                'student_id' => $rental->student_id,
                'rental_id' => $rental->id,
                'asset_id' => $rental->asset_id,
            ]);

            return;
        }

        if ($rental->status !== 'returned') {
            return;
        }

        // AssetCondition guarda rental_id como ObjectId.
        $condition = AssetCondition::query()
            ->where('rental_id', new ObjectId((string) $rental->id))
            ->where('type', 'return')
            ->orderBy('recorded_at', 'desc')
            ->first()
            ?->condition;

        $onTime = $this->onTime($rental->returned_at, $rental->due_at);

        $this->emit('rental.returned', $rental, [
            'student_id' => $rental->student_id,
            'rental_id' => $rental->id,
            'asset_id' => $rental->asset_id,
            'condition' => $condition,
            'on_time' => $onTime,
            'damage_charge_cents' => (int) ($rental->damage_charge_cents ?? 0),
        ]);

        // P3: devuelto a tiempo y sin daño.
        if ($onTime && $condition === 'good') {
            $this->emit('rental.returned_ok', $rental, [
                'student_id' => $rental->student_id,
                'rental_id' => $rental->id,
            ]);
        }
    }

    /** También lo llama LockerAssignmentService, que libera con una actualización masiva. */
    public function lockerReleased(LockerAssignment $assignment): void
    {
        try {
            $this->events->emit('locker.released', (string) $assignment->id, [
                'student_id' => $assignment->student_id,
                'assignment_id' => $assignment->id,
                'locker_id' => $assignment->locker_id,
                'period_id' => $assignment->period_id,
                'source' => $assignment->source,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function benefitAssigned(BenefitAssignment $assignment): void
    {
        $this->emit('benefit.assigned', $assignment, [
            'student_id' => $assignment->student_id,
            'benefit_assignment_id' => $assignment->id,
            'benefit' => $assignment->benefit,
            'quantity' => (int) $assignment->quantity,
            'call_id' => $assignment->call_id,
        ]);
    }

    /** También lo llama ServiceBenefitService al cancelar un saldo de impresiones. */
    public function benefitCancelled(BenefitAssignment $assignment): void
    {
        try {
            $this->emit('benefit.cancelled', $assignment, [
                'student_id' => $assignment->student_id,
                'benefit_assignment_id' => $assignment->id,
                'benefit' => $assignment->benefit,
                'released_quantity' => (int) ($assignment->cancellation['released_quantity'] ?? 0),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(Reservation|RestBooking $booking): array
    {
        $isFacility = $booking instanceof Reservation;

        return [
            'student_id' => $booking->student_id,
            'reservation_id' => $booking->id,
            'resource_type' => $isFacility ? 'facility' : 'rest_space',
            'resource_id' => $isFacility ? $booking->facility_id : $booking->rest_space_id,
            'start_at' => $booking->start_at,
        ];
    }

    private function onTime(mixed $returnedAt, mixed $dueAt): bool
    {
        return $returnedAt instanceof CarbonInterface
            && $dueAt instanceof CarbonInterface
            && $returnedAt->lessThanOrEqualTo($dueAt);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(string $entityAction, Model $model, array $payload): void
    {
        $this->events->emit($entityAction, (string) $model->getKey(), $payload);
    }
}
