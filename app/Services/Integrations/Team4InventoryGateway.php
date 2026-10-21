<?php

namespace App\Services\Integrations;

interface Team4InventoryGateway
{
    public function reserve(array $lines, string $orderId): array;

    // Confirma una reserva como salida de inventario real, solo despues de que el
    // pago quedo autorizado de verdad (no un resultado simulado). Antes no existia
    // este paso: el checkout dejaba el inventario en "reserved" para siempre.
    public function confirm(array $lines, string $orderId, ?string $paymentIntentId = null): array;

    public function release(array $lines, string $orderId): array;
}
