<?php

namespace App\Services\Integrations;

class MockTeam7RewardsGateway implements Team7RewardsGateway
{
    public function earn(array $sale): array
    {
        return ['status'=>'accepted','points'=>(int)floor(((float)$sale['total'])/2),'reference'=>'REW-'.strtoupper(substr(md5($sale['order_id']),0,10)),'provider'=>'team7-mock'];
    }

    public function reverse(array $sale): array
    {
        return ['status'=>'accepted','reference'=>'REW-REV-'.strtoupper(substr(md5($sale['order_id']),0,8)),'provider'=>'team7-mock'];
    }
}
