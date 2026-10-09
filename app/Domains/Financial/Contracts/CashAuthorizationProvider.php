<?php

namespace App\Domains\Financial\Contracts;

interface CashAuthorizationProvider
{
    // actorId is server-derived: service:<OAuth subject> or, later, user:<session ID>.
    // associationId is an external Module 6 identifier, never a wallet ID.
    public function allows(string $actorId, string $associationId, string $action): bool;
}
