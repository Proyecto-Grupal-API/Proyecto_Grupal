<?php

namespace App\Domains\Identity\Contracts;

/** Trusted internal contract. It does not authenticate a caller or grant roles. */
interface CapabilityAuthorization
{
    public function authorized(string $userId, string $capability, ?string $scopeType = null, ?string $scopeId = null): bool;
}
