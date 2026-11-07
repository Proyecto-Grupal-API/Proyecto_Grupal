<?php

namespace App\Domains\Financial\Contracts;

/** Local E2 administration boundary, not an E1 capability catalogue or human actor proof. */
interface InstitutionalBudgetAuthorization
{
    public function allows(string $actorId, string $action, ?string $budgetId = null): bool;
}
