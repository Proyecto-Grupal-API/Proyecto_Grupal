<?php

namespace App\Services\Integrations;

interface Team2PaymentGateway
{
    public function charge(array $paymentIntent): array;
    public function refund(array $paymentIntent, float $amount): array;
}
