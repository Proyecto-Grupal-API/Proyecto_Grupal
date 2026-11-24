<?php

namespace App\Services\PlatformModule;

use App\Models\PlatformModule\AnalyticsEvent;
use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\RewardProgramMembership;
use App\Services\PlatformModule\PointsLedger\DTO\EarnPointsRequest;
use App\Services\PlatformModule\PointsLedger\DTO\ReversePointsRequest;
use App\Services\PlatformModule\PointsLedger\Exception\DailyCapExceededException;
use App\Services\PlatformModule\PointsLedger\Exception\NoActiveEarningRuleException;
use App\Services\StudentStatusService;

/**
 * Contratos de integración del diseño (§15): «Acumular puntos por venta» (E3 → E7) y «Revertir puntos por
 * devolución». Convierte los eventos comerciales que Tienda entrega firmados en movimientos del ledger de puntos.
 *
 * Resultado de cada evento:
 *  - processed: produjo (o ya había producido) su movimiento de puntos;
 *  - ignored:   no corresponde ningún movimiento (el negocio no participa, el comprador no es alumno, tope agotado);
 *  - failed:    falta algo que la administración puede corregir (por ejemplo, una regla activa) y se puede reintentar.
 */
final class CommerceEventProcessor
{
    public const SALE_CONFIRMED = 'marketplace.order.confirmed.v1';

    public const SALE_REFUNDED = 'marketplace.order.refunded.v1';

    public const EVENTS = [self::SALE_CONFIRMED, self::SALE_REFUNDED];

    public function __construct(private PointsLedgerService $ledger, private StudentStatusService $students) {}

    public function handles(string $eventName): bool
    {
        return in_array($eventName, self::EVENTS, true);
    }

    /** @return array{status: string, detail: string, points?: int, shortfall?: int} */
    public function handle(AnalyticsEvent $event): array
    {
        $payload = (array) $event->payload;
        $folio = (string) ($payload['order_folio'] ?? '');
        $business = (string) ($payload['business_id'] ?? '');
        $buyer = (string) ($payload['buyer_id'] ?? '');
        if ($folio === '' || $business === '' || $buyer === '') {
            return $this->finish($event, ['status' => 'failed', 'detail' => 'El evento no trae folio, negocio o comprador.']);
        }

        $result = $event->event_name === self::SALE_REFUNDED
            ? $this->refund($folio, $business)
            : $this->sale($folio, $business, $buyer, (int) ($payload['total_cents'] ?? 0));

        return $this->finish($event, $result);
    }

    private function sale(string $folio, string $business, string $buyer, int $totalCents): array
    {
        if (! RewardProgramMembership::where('business_id', $business)->where('status', 'active')->exists()) {
            return ['status' => 'ignored', 'detail' => 'El negocio no participa en el programa de recompensas.'];
        }
        try {
            $this->students->forUserId($buyer);
        } catch (\Throwable) {
            return ['status' => 'ignored', 'detail' => 'El comprador no es un alumno registrado.'];
        }
        try {
            $result = $this->ledger->earn(new EarnPointsRequest(
                studentId: $buyer,
                businessId: $business,
                sourceDomain: 'marketplace',
                sourceReference: $folio,
                saleAmount: $totalCents / 100,
                idempotencyKey: 'marketplace:order:'.$folio,
                campaignId: null,
                metadata: ['order_folio' => $folio],
                actorId: 'system',
                actorRole: 'system',
            ));
        } catch (NoActiveEarningRuleException) {
            return ['status' => 'failed', 'detail' => 'El negocio no tiene una regla de acumulación activa.'];
        } catch (DailyCapExceededException) {
            return ['status' => 'ignored', 'detail' => 'El alumno ya alcanzó su tope de puntos del día.'];
        }

        return ['status' => 'processed', 'detail' => 'Venta confirmada: puntos acumulados.', 'points' => (int) $result->ledgerEntry->amount];
    }

    private function refund(string $folio, string $business): array
    {
        $earned = PointsLedger::where('source_domain', 'marketplace')->where('source_reference', $folio)
            ->where('business_id', $business)->where('type', 'earn')->first();
        if (! $earned) {
            return ['status' => 'ignored', 'detail' => 'Esa venta no había acumulado puntos.'];
        }
        $result = $this->ledger->reverse(new ReversePointsRequest(
            relatedLedgerId: (string) $earned->_id,
            reason: 'Devolución del pedido '.$folio,
            idempotencyKey: 'marketplace:refund:'.$folio,
            metadata: ['order_folio' => $folio],
            actorId: 'system',
            actorRole: 'system',
        ));

        return ['status' => 'processed', 'detail' => 'Devolución: puntos revertidos.', 'points' => (int) $result->ledgerEntry->amount,
            'shortfall' => (int) ($result->shortfallAmount ?? 0)];
    }

    private function finish(AnalyticsEvent $event, array $result): array
    {
        $event->update(['processed' => $result['status'] !== 'failed', 'processed_at' => $result['status'] !== 'failed' ? now() : null,
            'processing_error' => $result['status'] === 'failed' ? $result['detail'] : null]);

        return $result;
    }
}
