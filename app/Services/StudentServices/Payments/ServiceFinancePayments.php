<?php

namespace App\Services\StudentServices\Payments;

use App\Models\StudentServices\Lockers\LockerRequest;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Services\StudentServices\Lockers\LockerRequestService;
use App\Services\StudentServices\Services\ServiceOrderService;
use Illuminate\Database\Eloquent\Builder;
use MongoDB\BSON\ObjectId;

/**
 * Pago con la wallet de Finanzas de una solicitud de locker o de una orden
 * de impresión. Mismo esquema que las multas de Biblioteca:
 *
 *  1. start(): marca el registro (finance_requested) ANTES de llamar a
 *     Finanzas, prepara la solicitud y devuelve la pantalla donde el alumno
 *     autoriza el cobro.
 *  2. reconcile(): consulta a Finanzas y, si el pago está confirmado, lo
 *     registra con su payment_id.
 *  3. beforeCancel(): si ya se pidió el cobro, primero se cancela en
 *     Finanzas; si Finanzas ya lo había cobrado, se registra el pago en vez
 *     de cancelar. Con la integración apagada no se cancela: queda pendiente
 *     hasta que Finanzas confirme.
 *  4. guardOtherPayment(): con el cobro ya pedido a Finanzas no se registra
 *     un pago manual ni con beca, para no cobrar dos veces.
 */
class ServiceFinancePayments
{
    public function __construct(private readonly StudentServicesFinanceClient $finance) {}

    public function enabled(): bool
    {
        return $this->finance->enabled();
    }

    /**
     * @return string|null ruta de Finanzas para autorizar; null si ya quedó pagado
     */
    public function start(LockerRequest|ServiceOrder $record): ?string
    {
        if (! $this->enabled()) {
            throw new PaymentException('El pago con Finanzas no está habilitado.');
        }

        // La marca se guarda antes de contactar a Finanzas, aunque se pierda la respuesta.
        $this->pendingQuery($record)->where('finance_requested', '!=', true)->update(['finance_requested' => true]);
        $record->refresh();

        if (! $this->isPending($record) || $record->finance_requested !== true) {
            throw new PaymentException('Solo una solicitud pendiente de pago se puede pagar.');
        }

        $payment = $this->finance->prepare($this->concept($record), (string) $record->id, (string) $record->student_id, $this->amount($record));

        if ($payment['status'] === 'CONFIRMED') {
            $this->apply($record, (string) $payment['payment_id']);

            return null;
        }

        return $this->finance->checkoutPath($payment);
    }

    public function reconcile(LockerRequest|ServiceOrder $record): bool
    {
        $record->refresh();

        if ($this->isPaid($record)) {
            return true;
        }

        if (! $this->isPending($record) || $record->finance_requested !== true) {
            return false;
        }

        $payment = $this->finance->lookup($this->concept($record), (string) $record->id, (string) $record->student_id, $this->amount($record));

        if (($payment['status'] ?? null) !== 'CONFIRMED') {
            return false;
        }

        $this->apply($record, (string) $payment['payment_id']);

        return true;
    }

    /**
     * Llamar antes de cancelar localmente un registro pendiente.
     *
     * @throws PaymentException si Finanzas ya cobró (el pago queda registrado)
     */
    public function beforeCancel(LockerRequest|ServiceOrder $record): void
    {
        $record->refresh();

        if ($record->finance_requested !== true || ! $this->isPending($record)) {
            return;
        }

        // El cobro ya se pidió a Finanzas: sin su respuesta no sabemos si se
        // cobró, así que con la integración apagada la solicitud sigue pendiente.
        if (! $this->enabled()) {
            throw new PaymentException('La solicitud ya se envió a Finanzas y la conexión está apagada. Queda pendiente hasta que Finanzas confirme el cobro o la cancelación.');
        }

        $result = $this->finance->cancel($this->concept($record), (string) $record->id, (string) $record->student_id, $this->amount($record));

        if ($result['status'] === 'CONFIRMED') {
            $this->apply($record, (string) $result['payment_id']);

            throw new PaymentException('Finanzas ya había confirmado el pago; la solicitud quedó pagada.');
        }

        if ($result['status'] !== 'CANCELLED') {
            throw new PaymentException('Finanzas aún no confirmó el cierre de la solicitud de pago.');
        }
    }

    /**
     * Un pago manual o con beca no se registra si el cobro ya se pidió a Finanzas.
     *
     * @throws PaymentException
     */
    public function guardOtherPayment(LockerRequest|ServiceOrder $record): void
    {
        $record->refresh();

        if ($record->finance_requested === true) {
            throw new PaymentException('Esta solicitud ya tiene un cobro en Finanzas. Consulta ese pago en lugar de registrar otro.');
        }
    }

    public function canStart(LockerRequest|ServiceOrder $record, ?string $userId): bool
    {
        return $this->enabled() && $userId !== null && (string) $record->student_id === $userId
            && $this->isPending($record) && $this->amount($record) > 0;
    }

    public function canReconcile(LockerRequest|ServiceOrder $record, ?string $userId): bool
    {
        return $this->canStart($record, $userId) && $record->finance_requested === true;
    }

    private function apply(LockerRequest|ServiceOrder $record, string $paymentId): void
    {
        $record->refresh();

        if ($this->isPaid($record)) {
            return;
        }

        if ($record instanceof LockerRequest) {
            app(LockerRequestService::class)->confirmPayment($record, $paymentId);

            return;
        }

        app(ServiceOrderService::class)->markAsPaid($record, $paymentId);
    }

    private function concept(LockerRequest|ServiceOrder $record): string
    {
        return $record instanceof LockerRequest ? 'locker_rental' : 'print_order';
    }

    private function amount(LockerRequest|ServiceOrder $record): int
    {
        return $record instanceof LockerRequest
            ? (int) $record->amountInCents()
            : (int) $record->quoted_amount_cents;
    }

    private function isPending(LockerRequest|ServiceOrder $record): bool
    {
        return $record instanceof LockerRequest
            ? $record->request_type === 'paid' && $record->status === 'pending'
            : $record->status === 'awaiting_payment' && $record->payment_status === 'pending';
    }

    private function isPaid(LockerRequest|ServiceOrder $record): bool
    {
        return $record instanceof LockerRequest
            ? in_array($record->status, ['paid', 'assigned'], true) && $record->payment_reference !== null
            : $record->payment_status === 'paid';
    }

    /**
     * @return Builder<LockerRequest>|Builder<ServiceOrder>
     */
    private function pendingQuery(LockerRequest|ServiceOrder $record)
    {
        $query = $record::query()->where('_id', new ObjectId((string) $record->id));

        return $record instanceof LockerRequest
            ? $query->where('request_type', 'paid')->where('status', 'pending')
            : $query->where('status', 'awaiting_payment')->where('payment_status', 'pending');
    }
}
