<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Services\IdentityService;
use App\Services\PermisosComunidad;

final class CommunityCashAuthorizationProvider implements CashAuthorizationProvider
{
    public function __construct(private readonly IdentityService $identity, private readonly PermisosComunidad $community) {}

    public function allows(string $actorId, string $associationId, string $action): bool
    {
        if (! preg_match('/\Auser:([a-f0-9]{24})\z/', $actorId, $actor)
            || ! preg_match('/\A[a-f0-9]{24}\z/', $associationId)
            || ! in_array($associationId, config('financial.cash.community_associations', []), true)) {
            return false;
        }
        return $this->identity->isEligibleFinancialAccount($actor[1])
            && $this->community->permiteAccionCaja($actor[1], $associationId, $action);
    }
}
