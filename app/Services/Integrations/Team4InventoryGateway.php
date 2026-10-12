<?php

namespace App\Services\Integrations;

interface Team4InventoryGateway
{
    public function reserve(array $lines, string $orderId): array;
    public function release(array $lines, string $orderId): array;
}
