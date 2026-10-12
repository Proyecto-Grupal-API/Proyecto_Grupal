<?php

namespace App\Services\Integrations;

interface Team7RewardsGateway
{
    public function earn(array $sale): array;
    public function reverse(array $sale): array;
}
