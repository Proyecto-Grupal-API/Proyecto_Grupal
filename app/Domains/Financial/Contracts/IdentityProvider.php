<?php

namespace App\Domains\Financial\Contracts;

interface IdentityProvider
{
    public function validateQr(
        string $code,
        ?string $validatedByUserId = null,
        ?string $context = null,
        ?string $ipAddress = null
    ): array;
}