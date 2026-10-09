<?php

namespace App\Services\StudentServices\ServiceAccess;

use App\Models\StudentServices\Library\BookCopy;
use App\Models\StudentServices\Library\BookReservation;
use App\Models\StudentServices\Library\Loan;
use App\Models\StudentServices\Rentals\Asset;
use App\Models\StudentServices\Rentals\Rental;
use App\Models\StudentServices\ServiceAccess\ServiceCheckin;
use App\Models\StudentServices\ServiceAccess\ServiceEvent;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Services\StudentServices\Calendars\BookableResources;
use App\Services\StudentServices\Calendars\BookingService;
use App\Services\StudentServices\Calendars\BookingStatus;
use App\Services\StudentServices\Calendars\CalendarRuleChecker;
use App\Services\StudentServices\Library\FineService;
use App\Services\StudentServices\Library\LoanService;
use App\Services\StudentServices\Library\ReservationService;
use App\Services\StudentServices\Lockers\LockerAssignmentService;
use App\Services\StudentServices\Rentals\RentalService;
use App\Services\StudentServices\Services\ServiceOrderService;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;
use RuntimeException;

/**
 * Modulo 5.11 - Validación de acceso y uso.
 *
 * Punto único de escaneo NFC/QR. Resuelve la credencial (contrato con
 * el Equipo 1), verifica que el estudiante pueda realizar la operación
 * en el servicio indicado usando los servicios de dominio de cada módulo
 * del Equipo 5 (sin duplicar su lógica), y registra SIEMPRE el intento
 * en service_checkins; cuando la operación produce un efecto, publica
 * además un service_event.
 */
class ServiceAccessService
{
    /**
     * Operaciones permitidas por servicio.
     *
     * @var array<string, list<string>>
     */
    public const OPERATIONS = [
        'library' => ['loan', 'return'],
        'locker' => ['entry'],
        'facility' => ['checkin', 'checkout'],
        'rest' => ['checkin', 'checkout'],
        'rental' => ['pickup', 'return'],
        'service' => ['delivery'],
    ];

    public const METHODS = ['qr', 'nfc', 'manual'];

    public const RENTAL_CONDITIONS = ['good', 'damaged', 'maintenance', 'lost'];

    public function __construct(
        private StudentCredentialResolver $credentials,
        private BookingService $bookings,
        private FineService $fines,
        private LoanService $loans,
        private ReservationService $bookReservations,
        private LockerAssignmentService $lockers,
        private RentalService $rentals,
        private ServiceOrderService $serviceOrders
    ) {}

    /**
     * @param  array{credential: string, method: string, service: string, action: string, reference?: string|null, condition?: string|null}  $input
     */
    public function validate(array $input, ?string $operatorId = null): ServiceCheckin
    {
        $service = $input['service'];
        $action = $input['action'];
        $reference = trim((string) ($input['reference'] ?? ''));

        if (! in_array($action, self::OPERATIONS[$service] ?? [], true)) {
            throw new InvalidArgumentException('La operación no corresponde al servicio seleccionado.');
        }

        $student = $this->credentials->resolve($input['credential'], $input['method']);

        $base = [
            'credential_hint' => $this->credentialHint($input['credential']),
            'method' => $input['method'],
            'service' => $service,
            'action' => $action,
            'reference' => $reference !== '' ? $reference : null,
            'operator_id' => $operatorId,
        ];

        if ($student === null) {
            return $this->record($base + ['granted' => false, 'message' => 'Credencial no reconocida.']);
        }

        $base['student_id'] = $student['student_id'];
        $base['student_name'] = $student['name'];

        if ($student['status'] !== 'active') {
            return $this->record($base + ['granted' => false, 'message' => 'El estudiante no tiene un estatus activo.']);
        }

        try {
            $outcome = match ($service.'.'.$action) {
                'library.loan' => $this->libraryLoan($student['student_id'], $reference),
                'library.return' => $this->libraryReturn($student['student_id'], $reference),
                'locker.entry' => $this->lockerEntry($student['student_id'], $reference),
                'facility.checkin' => $this->bookingCheckIn(BookableResources::FACILITY, $student['student_id'], $reference),
                'facility.checkout' => $this->bookingCheckOut(BookableResources::FACILITY, $student['student_id'], $reference),
                'rest.checkin' => $this->bookingCheckIn(BookableResources::REST_SPACE, $student['student_id'], $reference),
                'rest.checkout' => $this->bookingCheckOut(BookableResources::REST_SPACE, $student['student_id'], $reference),
                'rental.pickup' => $this->rentalPickup($student['student_id'], $reference),
                'rental.return' => $this->rentalReturn($student['student_id'], $reference, (string) ($input['condition'] ?? 'good')),
                'service.delivery' => $this->serviceDelivery($student['student_id'], $reference),
                default => throw new InvalidArgumentException('Operación no soportada.'),
            };
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->record($base + ['granted' => false, 'message' => $exception->getMessage()]);
        }

        $checkin = $this->record([
            ...$base,
            'granted' => true,
            'message' => $outcome['message'],
            'reference' => $outcome['reference'],
            'reference_id' => $outcome['reference_id'],
        ]);

        ServiceEvent::create([
            'event_type' => $outcome['event'],
            'service' => $service,
            'action' => $action,
            'student_id' => $student['student_id'],
            'reference' => $outcome['reference'],
            'reference_id' => $outcome['reference_id'],
            'checkin_id' => (string) $checkin->id,
            'actor_id' => $operatorId,
            'data' => $outcome['data'] ?? [],
            'occurred_at' => now(),
        ]);

        return $checkin;
    }

    /**
     * @return array{message: string, reference: string, reference_id: string, event: string, data?: array<string, mixed>}
     */
    private function libraryLoan(string $studentId, string $reference): array
    {
        $copy = $this->findCopy($reference);

        if ($this->fines->hasPendingFines($studentId)) {
            throw new RuntimeException('El estudiante tiene multas de biblioteca pendientes; debe liquidarlas antes de un nuevo préstamo.');
        }

        if ($copy->status === 'reserved') {
            $reservation = BookReservation::query()
                ->where('assigned_copy_id', new ObjectId((string) $copy->id))
                ->where('status', 'ready')
                ->first();

            if ($reservation === null || (string) $reservation->student_id !== $studentId) {
                throw new RuntimeException("El ejemplar {$copy->code} está apartado para otro estudiante.");
            }

            $loan = $this->bookReservations->fulfillReservation($reservation, $this->loans);
        } else {
            $loan = $this->loans->createLoan($copy, $studentId);
        }

        return [
            'message' => "Préstamo registrado. Fecha de devolución: {$loan->due_at->format('d/m/Y')}.",
            'reference' => (string) $copy->code,
            'reference_id' => (string) $loan->id,
            'event' => 'library.loan_created',
        ];
    }

    /**
     * @return array{message: string, reference: string, reference_id: string, event: string, data?: array<string, mixed>}
     */
    private function libraryReturn(string $studentId, string $reference): array
    {
        $copy = $this->findCopy($reference);

        $loan = Loan::query()
            ->where('copy_id', new ObjectId((string) $copy->id))
            ->whereIn('status', ['active', 'overdue'])
            ->first();

        if ($loan === null) {
            throw new RuntimeException("El ejemplar {$copy->code} no tiene un préstamo activo.");
        }

        $this->assertOwner($loan->student_id, $studentId, 'El préstamo pertenece a otro estudiante.');

        $loan = $this->loans->refreshLoanStatus($loan);
        $wasOverdue = $loan->status === 'overdue';
        $loan = $this->loans->returnLoan($loan);

        return [
            'message' => $wasOverdue
                ? 'Devolución registrada CON ATRASO: registra la multa correspondiente en el módulo 5.2.'
                : 'Devolución registrada a tiempo.',
            'reference' => (string) $copy->code,
            'reference_id' => (string) $loan->id,
            'event' => 'library.loan_returned',
            'data' => ['overdue' => $wasOverdue],
        ];
    }

    /**
     * @return array{message: string, reference: string, reference_id: string, event: string, data?: array<string, mixed>}
     */
    private function lockerEntry(string $studentId, string $reference): array
    {
        $this->requireReference($reference, 'Captura el código o QR del locker.');

        $result = $this->lockers->validateAccess($reference);

        if (! ($result['granted'] ?? false)) {
            throw new RuntimeException($result['message'] ?? 'Acceso denegado.');
        }

        $this->assertOwner($result['student_id'] ?? null, $studentId, 'El locker está asignado a otro estudiante.');

        return [
            'message' => 'Acceso al locker autorizado'.(isset($result['ends_at']) ? " (vigente hasta {$result['ends_at']})." : '.'),
            'reference' => (string) ($result['locker_code'] ?? $reference),
            'reference_id' => (string) ($result['folio'] ?? $reference),
            'event' => 'locker.accessed',
        ];
    }

    /**
     * Check-in con folio, o sin folio: toma la reserva confirmada del
     * estudiante cuya ventana de llegada está abierta en este momento.
     *
     * @return array{message: string, reference: string, reference_id: string, event: string, data?: array<string, mixed>}
     */
    private function bookingCheckIn(string $type, string $studentId, string $reference): array
    {
        $booking = $reference !== ''
            ? $this->bookings->findBooking($type, $reference)
            : $this->currentBookingFor($type, $studentId);

        if ($booking === null) {
            throw new RuntimeException(
                $reference !== ''
                    ? "No existe una reserva con el folio {$reference}."
                    : 'El estudiante no tiene una reserva con check-in disponible en este momento.'
            );
        }

        $this->assertOwner($booking->student_id, $studentId, 'La reserva pertenece a otro estudiante.');

        $booking = $this->bookings->checkIn($type, $booking);
        $resource = $this->bookings->resourceOf($type, $booking);

        return [
            'message' => 'Entrada registrada en '.($resource?->name ?? 'el recurso')." hasta las {$booking->end_at->format('H:i')}.",
            'reference' => (string) $booking->folio,
            'reference_id' => (string) $booking->id,
            'event' => $type.'.checked_in',
        ];
    }

    /**
     * @return array{message: string, reference: string, reference_id: string, event: string, data?: array<string, mixed>}
     */
    private function bookingCheckOut(string $type, string $studentId, string $reference): array
    {
        $booking = $reference !== ''
            ? $this->bookings->findBooking($type, $reference)
            : BookableResources::bookingModel($type)::query()
                ->where('student_id', $studentId)
                ->where('status', BookingStatus::CHECKED_IN)
                ->orderBy('checked_in_at', 'desc')
                ->first();

        if ($booking === null) {
            throw new RuntimeException(
                $reference !== ''
                    ? "No existe una reserva con el folio {$reference}."
                    : 'El estudiante no tiene una reserva en uso.'
            );
        }

        $this->assertOwner($booking->student_id, $studentId, 'La reserva pertenece a otro estudiante.');

        $booking = $this->bookings->checkOut($type, $booking);

        return [
            'message' => 'Salida registrada; el espacio quedó liberado.',
            'reference' => (string) $booking->folio,
            'reference_id' => (string) $booking->id,
            'event' => $type.'.checked_out',
        ];
    }

    /**
     * @return array{message: string, reference: string, reference_id: string, event: string, data?: array<string, mixed>}
     */
    private function rentalPickup(string $studentId, string $reference): array
    {
        $rental = $this->findActiveRental($reference);

        $this->assertOwner($rental->student_id, $studentId, 'La renta pertenece a otro estudiante.');

        $rental = $this->rentals->refreshRentalStatus($rental);

        if ($rental->status === 'overdue') {
            throw new RuntimeException('La renta está vencida; corresponde registrar la devolución, no la entrega.');
        }

        $asset = Asset::find((string) $rental->asset_id);

        return [
            'message' => 'Entrega de '.($asset?->name ?? 'equipo')." validada. Devolver a más tardar el {$rental->due_at->format('d/m/Y')}.",
            'reference' => (string) ($asset?->inventory_item_id ?? $rental->id),
            'reference_id' => (string) $rental->id,
            'event' => 'rental.picked_up',
        ];
    }

    /**
     * @return array{message: string, reference: string, reference_id: string, event: string, data?: array<string, mixed>}
     */
    private function rentalReturn(string $studentId, string $reference, string $condition): array
    {
        if (! in_array($condition, self::RENTAL_CONDITIONS, true)) {
            throw new RuntimeException('La condición de devolución no es válida.');
        }

        $rental = $this->findActiveRental($reference);

        $this->assertOwner($rental->student_id, $studentId, 'La renta pertenece a otro estudiante.');

        $rental = $this->rentals->returnRental($rental, $condition, 'Devolución registrada desde validación de servicios (5.11).');
        $asset = Asset::find((string) $rental->asset_id);

        return [
            'message' => 'Devolución de '.($asset?->name ?? 'equipo').' registrada.',
            'reference' => (string) ($asset?->inventory_item_id ?? $rental->id),
            'reference_id' => (string) $rental->id,
            'event' => 'rental.returned',
            'data' => ['condition' => $condition],
        ];
    }

    /**
     * @return array{message: string, reference: string, reference_id: string, event: string, data?: array<string, mixed>}
     */
    private function serviceDelivery(string $studentId, string $reference): array
    {
        $this->requireReference($reference, 'Captura el folio de la solicitud.');

        $order = ServiceOrder::query()->where('folio', Str::upper($reference))->first();

        if ($order === null) {
            throw new RuntimeException("No existe una solicitud con el folio {$reference}.");
        }

        $this->assertOwner($order->student_id, $studentId, 'La solicitud pertenece a otro estudiante.');

        $order = $this->serviceOrders->markDelivered($order);

        return [
            'message' => 'Entrega registrada.',
            'reference' => (string) $order->folio,
            'reference_id' => (string) $order->id,
            'event' => 'service_order.delivered',
        ];
    }

    private function currentBookingFor(string $type, string $studentId): ?Model
    {
        $now = now();

        return BookableResources::bookingModel($type)::query()
            ->where('student_id', $studentId)
            ->where('status', BookingStatus::CONFIRMED)
            ->where('start_at', '<=', $now->copy()->addMinutes(CalendarRuleChecker::EARLY_CHECK_IN_MINUTES))
            ->where('end_at', '>', $now)
            ->orderBy('start_at')
            ->first();
    }

    private function findCopy(string $reference): BookCopy
    {
        $this->requireReference($reference, 'Captura el código del ejemplar.');

        $variants = $this->referenceVariants($reference);

        $copy = BookCopy::query()
            ->whereIn('code', $variants)
            ->orWhereIn('barcode', $variants)
            ->first();

        if ($copy === null) {
            throw new RuntimeException("No existe un ejemplar con el código {$reference}.");
        }

        return $copy;
    }

    private function findActiveRental(string $reference): Rental
    {
        $this->requireReference($reference, 'Captura el folio de la renta o el código de inventario del equipo.');

        $query = Rental::query()->whereIn('status', ['active', 'overdue']);

        if (preg_match('/^[a-f0-9]{24}$/i', $reference) === 1) {
            $rental = (clone $query)->where('_id', new ObjectId(strtolower($reference)))->first();

            if ($rental !== null) {
                return $rental;
            }
        }

        $asset = Asset::query()->whereIn('inventory_item_id', $this->referenceVariants($reference))->first();

        $rental = $asset !== null
            ? (clone $query)->where('asset_id', new ObjectId((string) $asset->id))->first()
            : null;

        if ($rental === null) {
            throw new RuntimeException("No existe una renta activa para {$reference}.");
        }

        return $rental;
    }

    /**
     * @return list<string>
     */
    private function referenceVariants(string $reference): array
    {
        return array_values(array_unique([$reference, Str::upper($reference), Str::lower($reference)]));
    }

    private function assertOwner(mixed $ownerId, string $studentId, string $message): void
    {
        if ((string) $ownerId !== $studentId) {
            throw new RuntimeException($message);
        }
    }

    private function requireReference(string $reference, string $message): void
    {
        if ($reference === '') {
            throw new RuntimeException($message);
        }
    }

    /**
     * Minimización de datos: solo se guardan los últimos caracteres de
     * la credencial escaneada.
     */
    private function credentialHint(string $credential): string
    {
        $value = trim($credential);

        if (mb_strlen($value) <= 4) {
            return str_repeat('•', mb_strlen($value));
        }

        return '••••'.mb_substr($value, -4);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(array $data): ServiceCheckin
    {
        return ServiceCheckin::create([
            'folio' => $this->bookings->newFolio('EVT'),
            'student_id' => null,
            'student_name' => null,
            'reference_id' => null,
            ...$data,
            'scanned_at' => now(),
        ]);
    }
}
