<?php

namespace App\Services\Integrations;

interface Team2PaymentGateway
{
    public function charge(array $paymentIntent): array;
    public function refund(array $paymentIntent, float $amount): array;
}

interface Team4InventoryGateway
{
    public function reserve(array $lines, string $orderId): array;
    public function release(array $lines, string $orderId): array;
}

interface Team7RewardsGateway
{
    public function earn(array $sale): array;
    public function reverse(array $sale): array;
}

interface Team1IdentityGateway
{
    public function resolveUser(string $userId): array;
}
