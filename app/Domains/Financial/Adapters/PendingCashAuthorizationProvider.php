<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\CashAuthorizationProvider;

class PendingCashAuthorizationProvider implements CashAuthorizationProvider
{
    public function allows(string $actorId, string $associationId, string $action): bool
    {
        return false;
    }
}
