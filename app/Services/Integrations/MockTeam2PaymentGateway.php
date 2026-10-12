<?php

namespace App\Services\Integrations;

class MockTeam2PaymentGateway implements Team2PaymentGateway
{
    public function charge(array $paymentIntent): array
    {
        return ['status'=>'authorized','reference'=>'MOCK-WALLET-'.strtoupper(substr(md5($paymentIntent['idempotency_key']),0,10)),'provider'=>'team2-mock'];
    }

    public function refund(array $paymentIntent, float $amount): array
    {
        return ['status'=>'refunded','reference'=>'MOCK-REFUND-'.strtoupper(substr(md5($paymentIntent['idempotency_key'].'refund'),0,10)),'amount'=>$amount,'provider'=>'team2-mock'];
    }
}
