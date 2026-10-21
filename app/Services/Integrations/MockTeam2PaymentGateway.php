<?php

namespace App\Services\Integrations;

class MockTeam2PaymentGateway implements Team2PaymentGateway
{
    public function charge(array $paymentIntent): array
    {
        // Si ya existe un payment_intent_id propio (generado por nosotros al crear el
        // PaymentIntent), lo usamos como referencia estable en vez de regenerar una
        // nueva cada vez — asi, cuando se conecte el cliente real de Equipo 2, el
        // mismo identificador se mantiene entre reintentos.
        $reference = $paymentIntent['payment_intent_id'] ?? ('MOCK-WALLET-'.strtoupper(substr(md5($paymentIntent['idempotency_key']),0,10)));
        return [
            'status' => 'authorized',
            'reference' => $reference,
            'payment_intent_id' => $paymentIntent['payment_intent_id'] ?? null,
            'provider' => 'team2-mock',
        ];
    }

    public function refund(array $paymentIntent, float $amount): array
    {
        $refundId = $paymentIntent['refund_id'] ?? ('MOCK-REFUND-'.strtoupper(substr(md5($paymentIntent['idempotency_key'].'refund'),0,10)));
        return [
            'status' => 'refunded',
            'reference' => $refundId,
            'refund_id' => $refundId,
            'amount' => $amount,
            'provider' => 'team2-mock',
        ];
    }
}
