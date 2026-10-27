<?php

namespace App\Domains\Financial\Integrations\Team4;

use App\Domains\Financial\Enums\RefundRequestStatus;
use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\PurchaseRefundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Internal bridge only. The caller must authenticate the payer and validate the order before paying. */
final class PurchasePaymentEvents
{
    public function __construct(private PaymentEventOutbox $outbox) {}

    public function paid(PurchasePayment $payment, ?array $context, bool $replay = false): void
    {
        // Older installations remain functional without a Marketplace context. No historical events are manufactured.
        if (! Schema::connection('sqlsrv')->hasTable('purchase_marketplace_links')) {
            if ($context !== null) {
                throw new LogicException('Aplica la migracion de enlaces Marketplace.');
            }

            return;
        }
        $this->requireTransaction();
        $link = DB::connection('sqlsrv')->table('purchase_marketplace_links')
            ->where('purchase_payment_id', $payment->public_id)->first();
        if ($context === null) {
            if ($link) {
                throw new ConflictHttpException('La repeticion debe conservar el contexto Marketplace.');
            }

            return;
        }
        if (array_diff(array_keys($context), ['order_id', 'business_id', 'items']) || ! isset($context['order_id'],$context['business_id'])) {
            throw new InvalidArgumentException('Contexto Marketplace no valido.');
        }
        $pi = strtolower((string) $payment->public_id);
        $canonical = PaymentEventPayload::canonical([
            'payment_intent_id' => $pi, 'order_id' => $context['order_id'], 'business_id' => $context['business_id'],
            'status' => 'PAID', 'amount_cents' => (int) $payment->total_amount_cents, 'currency' => $payment->currency,
            'occurred_at' => $payment->created_at->toISOString(), 'items' => $context['items'] ?? [],
        ]);
        if ($payment->status !== 'COMPLETADO' || $payment->business_id !== $canonical['business_id']) {
            throw new ConflictHttpException('La compra no corresponde al negocio o no esta completada.');
        }
        $normalized = ['order_id' => $canonical['order_id'], 'business_id' => $canonical['business_id'], 'items' => $canonical['items']];
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
        if ($link) {
            if (! hash_equals($link->context_hash, $hash)) {
                throw new ConflictHttpException('El contexto original de la compra es inmutable.');
            }

            return;
        }
        if ($replay) {
            throw new ConflictHttpException('No se puede vincular retroactivamente una compra pagada.');
        }
        DB::connection('sqlsrv')->table('purchase_marketplace_links')->insert([
            'purchase_payment_id' => $payment->public_id, 'payment_intent_id' => $pi,
            ...array_intersect_key($normalized, array_flip(['order_id', 'business_id'])),
            'order_key' => hash('sha256', json_encode([$canonical['business_id'], $canonical['order_id']], JSON_THROW_ON_ERROR)),
            'context_hash' => $hash, 'items' => json_encode($canonical['items'], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->outbox->record('purchase-paid:'.$pi, $canonical);
    }

    public function refunded(PurchasePayment $payment, PurchaseRefundRequest $refund): void
    {
        if (! Schema::connection('sqlsrv')->hasTable('purchase_marketplace_links')) {
            return;
        }
        $this->requireTransaction();
        $link = DB::connection('sqlsrv')->table('purchase_marketplace_links')->where('purchase_payment_id', $payment->public_id)->first();
        if (! $link) {
            return;
        }
        if ($refund->status !== RefundRequestStatus::COMPLETADA || strtolower($refund->purchase_payment_id) !== strtolower($payment->public_id)
            || ! $refund->completed_at || $refund->currency !== $payment->currency) {
            throw new LogicException('La devolucion no es una ejecucion completada de esta compra.');
        }
        // PurchaseRefundService holds the purchase row lock, serializing cumulative totals for this purchase.
        $total = (int) PurchaseRefundRequest::where('purchase_payment_id', $payment->public_id)
            ->where('status', RefundRequestStatus::COMPLETADA->value)->sum('total_amount_cents');
        if ($total > $payment->total_amount_cents || $refund->total_amount_cents <= 0) {
            throw new LogicException('Total devuelto inconsistente.');
        }
        $this->outbox->record('purchase-refund:'.strtolower($refund->public_id), [
            'payment_intent_id' => $link->payment_intent_id, 'order_id' => $link->order_id, 'business_id' => $link->business_id,
            'status' => $total === $payment->total_amount_cents ? 'REFUNDED' : 'PARTIALLY_REFUNDED',
            'amount_cents' => (int) $refund->total_amount_cents, 'currency' => $payment->currency,
            'occurred_at' => $refund->completed_at->toISOString(), 'refund_id' => strtolower($refund->public_id),
            // Money restored is not proof of a physical return. Do not copy all purchased items into a partial refund.
            'items' => [],
        ]);
    }

    private function requireTransaction(): void
    {
        if (DB::connection('sqlsrv')->transactionLevel() < 1) {
            throw new LogicException('El evento debe compartir la transaccion financiera.');
        }
    }
}
