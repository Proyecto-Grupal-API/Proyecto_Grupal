<?php

namespace App\Services\Integrations;

class MockTeam4InventoryGateway implements Team4InventoryGateway
{
    public function reserve(array $lines, string $orderId): array
    {
        return ['status'=>'reserved','reservation_id'=>'INV-'.strtoupper(substr(md5($orderId),0,10)),'lines'=>$lines,'provider'=>'team4-mock'];
    }

    public function confirm(array $lines, string $orderId, ?string $paymentIntentId = null): array
    {
        return ['status'=>'confirmed','reservation_id'=>'INV-'.strtoupper(substr(md5($orderId),0,10)),'payment_intent_id'=>$paymentIntentId,'lines'=>$lines,'provider'=>'team4-mock'];
    }

    public function release(array $lines, string $orderId): array
    {
        return ['status'=>'released','order_id'=>$orderId,'provider'=>'team4-mock'];
    }
}
