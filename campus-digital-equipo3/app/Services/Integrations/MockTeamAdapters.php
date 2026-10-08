<?php

namespace App\Services\Integrations;

class MockTeam2PaymentGateway implements Team2PaymentGateway
{
    public function charge(array $paymentIntent): array
    {
        return [
            'status' => 'authorized',
            'reference' => 'MOCK-WALLET-'.strtoupper(substr(md5($paymentIntent['idempotency_key']), 0, 10)),
            'provider' => 'team2-mock',
        ];
    }

    public function refund(array $paymentIntent, float $amount): array
    {
        return [
            'status' => 'refunded',
            'reference' => 'MOCK-REFUND-'.strtoupper(substr(md5($paymentIntent['idempotency_key'].'refund'), 0, 10)),
            'amount' => $amount,
        ];
    }
}

class MockTeam4InventoryGateway implements Team4InventoryGateway
{
    public function reserve(array $lines, string $orderId): array
    {
        return [
            'status' => 'reserved',
            'reservation_id' => 'INV-'.strtoupper(substr(md5($orderId), 0, 10)),
            'lines' => $lines,
        ];
    }

    public function release(array $lines, string $orderId): array
    {
        return ['status' => 'released', 'order_id' => $orderId];
    }
}

class MockTeam7RewardsGateway implements Team7RewardsGateway
{
    public function earn(array $sale): array
    {
        return [
            'status' => 'accepted',
            'points' => (int) floor(((float) $sale['total']) / 2),
            'reference' => 'REW-'.strtoupper(substr(md5($sale['order_id']), 0, 10)),
        ];
    }

    public function reverse(array $sale): array
    {
        return ['status' => 'accepted', 'reference' => 'REW-REV-'.strtoupper(substr(md5($sale['order_id']), 0, 8))];
    }
}

class MockTeam1IdentityGateway implements Team1IdentityGateway
{
    public function resolveUser(string $userId): array
    {
        return ['user_id' => $userId, 'status' => 'active', 'roles' => ['admin']];
    }
}
