<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Order;
use App\Models\Marketplace\PaymentIntent;
use App\Services\ClienteModuloHttp;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MarketplaceWallet
{
    private const API = '/api/v1/financial/marketplace/payment-requests';

    public function __construct(private ClienteModuloHttp $http, private MarketplaceIntegration $inventory) {}

    public function enabled(): bool
    {
        return config('marketplace.wallet_checkout.enabled') === true;
    }

    // MarketplaceOrders serializes all state transitions for an order.
    public function start(Order $order): ?string
    {
        abort_unless($this->enabled() && $order->payment_method === 'wallet', 404);
        abort_unless($order->status === 'awaiting_payment' && $order->payment_status === 'pending'
            && empty($order->wallet_checkout['close_as']) && $order->hold_expires_at?->isFuture(), 409, 'Este pedido ya no admite un cobro.');
        $client = config('marketplace.wallet_checkout.client_id');
        abort_unless(is_string($client) && $client !== '', 503, 'Falta configurar el cliente de Finanzas.');
        $checkout = $order->wallet_checkout;
        $existing = $checkout ? $this->request($order, 'read') : null;
        if ($existing && $existing['status'] !== 'PENDING') {
            $this->apply($order, $existing);

            return null;
        }

        $deadline = CarbonImmutable::instance($order->hold_expires_at);
        if ($order->reserve_payload['items'] ?? []) {
            $reservation = $this->reservation($order);
            abort_unless($reservation['status'] === 'RESERVED' && $reservation['expires']->isFuture(), 409, 'La reserva de Inventarios ya no está disponible.');
            $deadline = $deadline->min($reservation['expires']);
        }

        $cutoff = $deadline->startOfSecond();
        abort_unless($cutoff->isFuture(), 409, 'La reserva ya venció.');
        if ($checkout) {
            abort_unless($this->date($checkout['deadline'])->lte($cutoff), 409, 'La vigencia de la reserva cambió.');
        } else {
            $order->update(['wallet_checkout' => [
                'client_id' => $client, 'deadline' => $cutoff->toISOString(),
                'request_id' => null,
            ]]);
        }

        abort_if($existing === null && ! empty($checkout['request_id']), 503, 'Finanzas no encontró la solicitud ya registrada.');
        $result = $existing ?? $this->request($order, 'prepare');
        $this->apply($order, $result);

        return $result['status'] === 'PENDING' ? $result['confirmation_path'] : null;
    }

    public function recover(Order $order): void
    {
        if ($order->status === 'payment_reconciliation') {
            abort_unless($this->enabled(), 503);
            $this->confirmInventory($order);

            return;
        }

        $checkout = $order->wallet_checkout;
        $close = ! empty($checkout['close_as']) || $this->date($checkout['deadline'])->lte(now());
        $result = $this->request($order, $close ? 'cancel' : 'read');
        // A missing row after a lost prepare is ambiguous. Retain the original order and reservation.
        if ($result !== null) {
            $this->apply($order, $result);
        }
    }

    private function request(Order $order, string $action): ?array
    {
        $checkout = $order->wallet_checkout;
        $client = config('marketplace.wallet_checkout.client_id');
        abort_unless($this->enabled() && is_string($client) && $client !== '' && $checkout['client_id'] === $client,
            503, 'Restaura el cliente original de Finanzas para recuperar esta solicitud.');
        $origin = ['order_id' => (string) $order->getKey(), 'business_id' => $order->business_id];
        try {
            $http = $this->http->para('marketplace_finance', 'financial:purchase:'.$action)->withOptions(['allow_redirects' => false]);
            $response = match ($action) {
                'prepare' => $http->withHeader('Idempotency-Key', 'marketplace-wallet:'.$order->getKey())->post(self::API, $origin + [
                    'buyer_id' => $order->buyer_id, 'amount_cents' => $order->total_cents, 'currency' => $order->currency,
                    'description' => 'Pedido '.$order->folio, 'items' => $order->reserve_payload['items'] ?? [],
                    'reservation_expires_at' => $checkout['deadline'],
                ]),
                'read' => $http->get(self::API.'/by-order', $origin),
                'cancel' => $http->post(self::API.'/by-order/cancel', $origin),
            };
        } catch (HttpClientException|RuntimeException) {
            abort(503, 'Finanzas no confirmó el resultado. Puedes volver a consultar este pedido.');
        }
        if ($action !== 'prepare' && $response->notFound()) {
            return null;
        }
        abort_unless($response->successful(), 503, 'Finanzas no confirmó el resultado. Puedes volver a consultar este pedido.');

        $data = $response->json('data');
        $requestId = $data['payment_request_id'] ?? null;
        $status = $data['status'] ?? null;
        $paymentId = $data['payment_intent_id'] ?? null;
        abort_unless(is_array($data) && ($data['order_id'] ?? null) === $origin['order_id']
            && ($data['business_id'] ?? null) === $origin['business_id'] && ($data['amount_cents'] ?? null) === $order->total_cents
            && ($data['currency'] ?? null) === $order->currency && is_string($requestId) && Str::isUuid($requestId)
            && (empty($checkout['request_id']) || $checkout['request_id'] === $requestId)
            && ($data['confirmation_path'] ?? null) === '/finanzas/compras/solicitudes/'.$requestId
            && in_array($status, ['PENDING', 'PAID', 'EXPIRED', 'CANCELLED'], true)
            && ($status === 'PAID' ? (is_string($paymentId) && Str::isUuid($paymentId)) : $paymentId === null)
            && $this->date($data['expires_at'] ?? null)->lte($this->date($checkout['deadline'])),
            503, 'La respuesta de Finanzas no coincide con el pedido.');

        // A lookup can derive EXPIRED from the clock. Cancellation locks the SQL row against an in-flight charge.
        if ($status === 'EXPIRED' && $action !== 'cancel') {
            return $this->request($order, 'cancel') ?? abort(503, 'Finanzas aún no confirmó el vencimiento.');
        }

        return $data;
    }

    private function apply(Order $order, array $result): void
    {
        $checkout = array_replace($order->wallet_checkout, ['request_id' => $result['payment_request_id']]);
        if ($result['status'] === 'PENDING') {
            $order->update(['wallet_checkout' => $checkout]);

            return;
        }

        $paid = $result['status'] === 'PAID';
        $status = $paid ? 'payment_reconciliation' : strtolower($result['status']);
        if ($paid) {
            $checkout['payment_id'] = $result['payment_intent_id'];
        }

        DB::connection('marketplace')->transaction(function () use ($order, $checkout, $paid, $status) {
            $intent = PaymentIntent::where('order_id', (string) $order->getKey())->sole();
            $intent->update(['provider' => 'financial_wallet', 'status' => $paid ? 'paid' : $status,
                'reference' => $checkout['payment_id'] ?? null]);
            $order->update(['wallet_checkout' => $checkout, 'status' => $status,
                'payment_status' => $paid ? 'paid' : 'pending',
                'history' => [...($order->history ?? []), ['status' => $status, 'actor_id' => 'SYSTEM', 'at' => now()->toISOString()]]]);
        });
        if ($paid) {
            $this->confirmInventory($order);
        }
    }

    private function confirmInventory(Order $order): void
    {
        $paymentId = $order->wallet_checkout['payment_id'];
        if (! ($order->reserve_payload['items'] ?? [])) {
            $order->update(['status' => 'confirmed']);

            return;
        }

        $reservation = $this->reservation($order);
        if ($reservation['status'] === 'RELEASED' || ($reservation['status'] === 'RESERVED' && ! $reservation['expires']->isFuture())) {
            $order->update(['status' => 'refund_review', 'inventory_state' => strtolower($reservation['status'])]);

            return;
        }
        if ($reservation['status'] === 'CONFIRMED') {
            abort_unless(($reservation['payment']['payment_intent_id'] ?? null) === $paymentId
                && ($reservation['payment']['status'] ?? null) === 'PAID', 503, 'La venta existente requiere conciliación.');
        } else {
            $result = $this->inventory->inventory($order->business_id, 'POST', $this->reservationPath($order).'/confirm', [
                'order_id' => (string) $order->getKey(), 'payment_intent_id' => $paymentId,
                'idempotency_key' => 'marketplace-confirm:'.$order->getKey(),
            ]);
            abort_unless(($result['reservation_id'] ?? null) === $order->reservation_id && ($result['status'] ?? null) === 'CONFIRMED',
                503, 'Inventarios aún no confirma la venta.');
        }
        $order->update(['status' => 'confirmed', 'inventory_state' => 'confirmed']);
    }

    private function reservation(Order $order): array
    {
        $result = $this->inventory->inventory($order->business_id, 'GET', $this->reservationPath($order));
        abort_unless(($result['reservation_id'] ?? null) === $order->reservation_id
            && ($result['order_id'] ?? null) === (string) $order->getKey()
            && in_array($result['status'] ?? null, ['RESERVED', 'RELEASED', 'CONFIRMED'], true), 503, 'Inventarios devolvió una reserva distinta.');

        return $result + ['expires' => $this->date($result['expires_at'] ?? null)];
    }

    private function reservationPath(Order $order): string
    {
        abort_unless(is_string($order->reservation_id) && $order->reservation_id !== '', 409, 'La reserva de este pedido aún no está confirmada.');

        return 'integration/reservations/'.rawurlencode($order->reservation_id);
    }

    private function date(mixed $date): CarbonImmutable
    {
        abort_unless(is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $date),
            503, 'La vigencia devuelta no es válida.');
        try {
            return CarbonImmutable::parse($date);
        } catch (InvalidFormatException) {
            abort(503, 'La vigencia devuelta no es válida.');
        }
    }
}
