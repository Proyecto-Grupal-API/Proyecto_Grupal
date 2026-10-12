<?php

namespace App\Services\Integrations;

interface Team1IdentityGateway
{
    public function resolveUser(string $userId): array;
}
